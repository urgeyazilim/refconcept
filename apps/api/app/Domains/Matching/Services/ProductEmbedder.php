<?php

declare(strict_types=1);

namespace App\Domains\Matching\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Matching\Enums\EmbeddingSource;
use App\Domains\Matching\Models\ProductEmbedding;
use App\Domains\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a product into a point in space.
 *
 * The whole reason vectors are here: a customer asking for "warm minimalist oak" should
 * find a sofa a seller described as "İskandinav meşe iskeletli" without either phrase
 * containing the other. Full-text search cannot do that, and a synonym list large enough
 * to fake it is a list somebody has to maintain forever.
 *
 * What gets embedded is a *deliberate* sentence rather than the raw description. A seller's
 * prose contains delivery terms, warranty text and the shop's name, and every word of it
 * pulls the vector away from what the product actually is. So the text is assembled from
 * the fields that describe the thing itself, in a fixed order, and the assembly is hashed —
 * which is also what makes re-embedding cheap: a product whose description has not changed
 * does not need a second call.
 */
final class ProductEmbedder
{
    /**
     * How long a search phrase keeps its vector.
     *
     * Long enough to collapse a busy afternoon of repeated terms, short enough that the
     * store is not a record of what people searched for.
     */
    private const QUERY_VECTOR_TTL = 3600;

    public function __construct(private readonly AiJobDispatcher $dispatcher) {}

    /**
     * Embeds a product if its text has changed since last time.
     *
     * Returns null when nothing needed doing, which is the common case on a re-run over a
     * catalogue — and the caller reporting "0 embedded, 1200 unchanged" is more useful than
     * one reporting 1200 successes it did not achieve.
     */
    public function embed(Product $product, bool $force = false): ?ProductEmbedding
    {
        $text = $this->textFor($product);

        if ($text === '') {
            return null;
        }

        $hash = hash('sha256', $text);

        $existing = ProductEmbedding::query()
            ->where('product_id', $product->getKey())
            ->ofSource(EmbeddingSource::Text)
            ->first();

        if (! $force && $existing !== null && $existing->content_hash === $hash) {
            return null;
        }

        $job = $this->dispatcher->runInline(
            task: AiTask::TextEmbedding,
            input: ['prompt' => $text],
            subject: $product,
            // Catalogue work, paid for by the platform. Nobody's wallet is involved.
            creditCostOverride: 0,
        );

        if ($job->status !== AiJobStatus::Succeeded) {
            throw new RuntimeException(sprintf(
                'Ürün vektörü üretilemedi (%s): %s',
                $product->slug,
                $job->failure_kind?->label() ?? 'bilinmeyen hata',
            ));
        }

        /** @var array<int, float> $vector */
        $vector = (array) ($job->output['embedding'] ?? []);

        if ($vector === []) {
            throw new RuntimeException('Sağlayıcı boş bir vektör döndürdü.');
        }

        return $this->store($product, $vector, $hash, $this->modelOf($job->output));
    }

    /**
     * Embeds a customer's phrase, for searching with.
     *
     * Never stored in a table. Keeping a row of everything anybody has ever typed into a
     * search box would be a privacy liability with no corresponding use.
     *
     * Cached for an hour, though, and the difference matters. Before this, **every**
     * catalogue search made a synchronous call to the embedding provider: a network round
     * trip on the most-used endpoint on the site, a cost per search, and a search box whose
     * latency was somebody else's uptime. Search terms repeat enormously — "koltuk" is not
     * a phrase one person types once — so a cache collapses almost all of it.
     *
     * The key is a hash and the value is a vector, so nothing readable is written down, and
     * an hour is short enough that a busy afternoon's terms are gone by the evening. That
     * is a deliberate trade rather than a free win: it is a smaller exposure than a table
     * of phrases, and it is not zero.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $text): array
    {
        $key = 'search-vector:'.hash('sha256', mb_strtolower(trim($text)));

        /** @var array<int, float>|null $cached */
        $cached = Cache::get($key);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $vector = $this->requestQueryVector($text);

        if ($vector !== []) {
            Cache::put($key, $vector, self::QUERY_VECTOR_TTL);
        }

        return $vector;
    }

    /**
     * The call itself, separated so the caching above reads as caching.
     *
     * @return array<int, float>
     */
    private function requestQueryVector(string $text): array
    {
        $job = $this->dispatcher->runInline(
            task: AiTask::TextEmbedding,
            input: ['prompt' => $text],
            creditCostOverride: 0,
        );

        if ($job->status !== AiJobStatus::Succeeded) {
            throw new RuntimeException('Arama vektörü üretilemedi.');
        }

        /** @var array<int, float> $vector */
        $vector = (array) ($job->output['embedding'] ?? []);

        return $vector;
    }

    /**
     * The sentence that describes the product and nothing else.
     *
     * Order is fixed and category comes first, because an embedding is a summary and what
     * comes first carries more weight. Delivery terms, warranty text and the seller's name
     * are deliberately absent: every word of those pulls the vector away from the thing
     * being sold, and two sofas from the same shop should not be similar *because* of the
     * shop.
     *
     * The description is truncated rather than dropped. A thousand words of prose dilutes
     * everything above it, and the first couple of hundred characters is where a seller
     * says what the thing is.
     */
    public function textFor(Product $product): string
    {
        $product->loadMissing(['primaryCategory', 'brand', 'style', 'skus.dimensions']);

        $parts = array_filter([
            $product->primaryCategory?->name,
            $product->name,
            $product->brand?->name,
            $product->style?->name,
            $this->dimensionPhrase($product),
            $product->description === null ? null : mb_substr($product->description, 0, 300),
        ]);

        return trim(implode('. ', $parts));
    }

    /**
     * Writes the vector, replacing whatever was there.
     *
     * A raw expression rather than an Eloquent attribute: pgvector has no cast, and a
     * 768-element array round-tripping through the model layer would be a real cost for
     * something no PHP code reads.
     *
     * @param  array<int, float>  $vector
     */
    public function store(Product $product, array $vector, string $hash, string $model): ProductEmbedding
    {
        $literal = ProductEmbedding::toVectorLiteral($vector);

        /*
         * One statement, and it has to be: the vector column is NOT NULL, so inserting the
         * row through Eloquent and setting the vector afterwards would fail on the insert.
         * The upsert also makes a re-embed a single write rather than a read, a decision
         * and a write — which matters when a catalogue pass touches thousands of rows.
         */
        DB::statement(<<<'SQL'
            INSERT INTO product_embeddings (id, product_id, source, model, content_hash, embedding, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?::vector, now(), now())
            ON CONFLICT (product_id, source) DO UPDATE
            SET model = EXCLUDED.model,
                content_hash = EXCLUDED.content_hash,
                embedding = EXCLUDED.embedding,
                updated_at = now()
        SQL, [
            (string) Str::uuid7(),
            $product->getKey(),
            EmbeddingSource::Text->value,
            $model,
            $hash,
            $literal,
        ]);

        return ProductEmbedding::query()
            ->where('product_id', $product->getKey())
            ->ofSource(EmbeddingSource::Text)
            ->firstOrFail();
    }

    /**
     * Products that would benefit from a pass.
     *
     * Only what a customer can actually be shown: embedding a draft listing spends money on
     * a product that cannot appear in a result.
     *
     * @return Builder<Product>
     */
    public function pending(): Builder
    {
        return Product::query()->publiclyVisible()->with(['primaryCategory', 'brand', 'style']);
    }

    private function dimensionPhrase(Product $product): ?string
    {
        $dimensions = $product->skus
            ->map(static fn ($sku) => $sku->dimensions)
            ->filter()
            ->first();

        if ($dimensions === null || $dimensions->width_mm === null) {
            return null;
        }

        // Centimetres, because that is how furniture is discussed and how a description
        // written by a person will phrase it. The filters use millimetres; this is prose.
        return sprintf(
            '%d cm genişlik',
            (int) round($dimensions->width_mm / 10),
        );
    }

    /**
     * @param  array<string, mixed>|null  $output
     */
    private function modelOf(?array $output): string
    {
        // The job records which model ran; falling back to a marker rather than an empty
        // string keeps "which model produced this vector" answerable either way.
        return is_string($output['model'] ?? null) ? $output['model'] : 'unknown';
    }
}

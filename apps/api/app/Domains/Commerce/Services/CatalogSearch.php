<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Matching\Enums\EmbeddingSource;
use App\Domains\Matching\Models\ProductEmbedding;
use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Searching the catalogue, three ways at once.
 *
 * A furniture search box receives three kinds of thing and they need different machinery:
 *
 *  - **A name.** "Bergama halı". Trigram similarity, because it will be misspelled.
 *  - **Words from a description.** "meşe ayaklı". Full-text search, which the products
 *    table already maintains a `tsvector` for.
 *  - **A description of a feeling.** "sıcak ve sade bir salon". Neither of the above finds
 *    anything, because none of those words appear in a product. The vector does.
 *
 * The three are fused rather than tried in turn. Falling back — keyword first, vector only
 * when it finds nothing — sounds sensible and behaves badly: a query that matches one
 * product by name would never see the twenty better ones that match by meaning.
 *
 * Fusion is by rank, not by score. A trigram similarity, a `ts_rank` and a cosine distance
 * are three numbers on three unrelated scales, and adding them is arithmetic without
 * meaning. Reciprocal rank fusion asks each method only for an ordering, which is the one
 * thing all three genuinely produce.
 */
final class CatalogSearch
{
    /**
     * The constant in reciprocal rank fusion.
     *
     * 60 is the value the original paper settled on and it is not magic: it decides how
     * quickly a method's contribution decays down its own ranking. Smaller makes the first
     * result of each method dominate; larger flattens everything towards a tie.
     */
    private const RRF_K = 60;

    /** How deep each method goes before fusion. */
    private const PER_METHOD = 60;

    /**
     * How far a vector may be and still be worth ranking.
     *
     * A backstop rather than the real control — see {@see rank()} for why the vector is
     * not allowed to decide *whether* anything matched. Measured against the live
     * embedding model, the nearest neighbour of pure nonsense sits around 0.35 and a
     * genuine match around 0.30, so this ceiling only removes the obviously distant.
     */
    private const MAX_DISTANCE = 0.45;

    public function __construct(private readonly ProductEmbedder $embedder) {}

    /**
     * Product ids in relevance order, best first.
     *
     * Ids rather than models, because the caller applies its own filters and paginates —
     * and a search that returned hydrated products would have done work the filters then
     * throw away.
     *
     * @return array<int, string>
     */
    public function rank(string $term, ?string $roomTypeHint = null): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $byName = $this->byName($term);
        $byText = $this->byText($term);

        /*
         * The lexical methods decide *whether* anything matched; the vector decides the
         * order and widens the net.
         *
         * This asymmetry is not a stylistic choice, it is what the numbers require. A
         * nearest-neighbour search always has a nearest neighbour, so on its own it can
         * never answer "nothing matches" — and measured against the live embedding model
         * the distances do not separate the two cases: pure nonsense lands about 0.35 from
         * its nearest product and a real keyword match about 0.30. Six hundredths is not a
         * margin anybody should build a search box on.
         *
         * So a query with no lexical footing returns nothing. That costs the purely
         * semantic case — "sıcak ve sade bir salon", which matches no word in any product —
         * and paying it is better than a search box that answers gibberish with a page of
         * sofas. See TEST_REPORT.md for what enabling that case would need.
         */
        if ($byName === [] && $byText === []) {
            return [];
        }

        $rankings = array_filter([
            $byName,
            $byText,
            $this->byMeaning($term, $roomTypeHint),
        ], static fn (array $ranking): bool => $ranking !== []);

        return $this->fuse($rankings);
    }

    /**
     * Applies a ranking to a query without losing the order.
     *
     * PostgreSQL returns rows in whatever order suits it, so the ordering has to be carried
     * into SQL explicitly. A `CASE` over the id list does it in one statement — the
     * alternative, sorting in PHP after the fact, would break pagination.
     *
     * @param  Builder<Product>  $query
     * @param  array<int, string>  $orderedIds
     * @return Builder<Product>
     */
    public function applyRanking(Builder $query, array $orderedIds): Builder
    {
        if ($orderedIds === []) {
            // A search that matched nothing returns nothing. Falling back to "everything"
            // would show a customer the whole catalogue and look like the search is broken.
            return $query->whereRaw('1 = 0');
        }

        $cases = [];
        $bindings = [];

        foreach (array_values($orderedIds) as $position => $id) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $position;
        }

        return $query
            ->whereIn('products.id', $orderedIds)
            ->orderByRaw('CASE products.id '.implode(' ', $cases).' END', $bindings);
    }

    /**
     * Counts for the filters a customer has not yet applied.
     *
     * Computed from the same conditions as the results, minus the facet's own filter —
     * otherwise selecting "modern" would show "modern (12)" and every other style at zero,
     * which tells the customer nothing they did not already know.
     *
     * @param  Builder<Product>  $base  the query with every filter *except* facets applied
     * @return array<string, array<int, array{value: string, label: string, count: int}>>
     */
    public function facets(Builder $base): array
    {
        return [
            'categories' => $this->categoryFacet($base),
            'styles' => $this->styleFacet($base),
            'price_bands' => $this->priceFacet($base),
        ];
    }

    // --- the three rankings ---------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function byName(string $term): array
    {
        return DB::table('products')
            ->select('id')
            ->where('moderation_status', 'approved')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            /*
             * `word_similarity`, not `similarity`, and the difference matters.
             *
             * `similarity` compares the query to the *whole* name, so it falls as the name
             * gets longer: "Bergama" against "Bergama Halısı 1787681531592" scores 0.276,
             * which forces a threshold low enough that a long query then matches almost
             * anything sharing a word. `word_similarity` asks how well the query matches
             * the best-matching part of the name, which is what a search box actually
             * means — the same pair scores 1.000, and a long query stops dragging in
             * unrelated products.
             *
             * 0.5 is measured rather than guessed: "Bergama" scores 1.000, the misspelling
             * "Bergma" 0.571, and an unrelated name sharing one word falls well below.
             */
            ->whereRaw('word_similarity(?, name) > 0.5', [$term])
            ->orderByRaw('word_similarity(?, name) desc', [$term])
            ->limit(self::PER_METHOD)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function byText(string $term): array
    {
        return DB::table('products')
            ->select('id')
            ->where('moderation_status', 'approved')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereRaw("search_document @@ plainto_tsquery('simple', ?)", [$term])
            ->orderByRaw("ts_rank(search_document, plainto_tsquery('simple', ?)) desc", [$term])
            ->limit(self::PER_METHOD)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function byMeaning(string $term, ?string $roomTypeHint): array
    {
        try {
            $vector = $this->embedder->embedQuery(
                $roomTypeHint === null ? $term : $term.' '.$roomTypeHint,
            );
        } catch (Throwable) {
            /*
             * No vector, no semantic ranking — and the other two still work. A search box
             * that returns an error because an embedding provider is having a bad morning
             * would be a far worse failure than a slightly less clever ordering.
             */
            return [];
        }

        if ($vector === []) {
            return [];
        }

        $literal = ProductEmbedding::toVectorLiteral($vector);

        return DB::table('product_embeddings as pe')
            ->join('products as p', 'p.id', '=', 'pe.product_id')
            ->select('p.id')
            ->where('pe.source', EmbeddingSource::Text->value)
            ->where('p.moderation_status', 'approved')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            // The ceiling that turns a sort into a search — see MAX_DISTANCE.
            ->whereRaw('(pe.embedding <=> ?::vector) < ?', [$literal, self::MAX_DISTANCE])
            ->orderByRaw('pe.embedding <=> ?::vector asc', [$literal])
            ->limit(self::PER_METHOD)
            ->pluck('id')
            ->all();
    }

    /**
     * Reciprocal rank fusion.
     *
     * Each method contributes `1 / (k + position)` for everything it ranked. A product all
     * three agree on rises above one any single method loved, which is the behaviour worth
     * having: three weak signals agreeing is stronger evidence than one strong signal
     * alone.
     *
     * @param  array<int, array<int, string>>  $rankings
     * @return array<int, string>
     */
    private function fuse(array $rankings): array
    {
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach (array_values($ranking) as $position => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + (1 / (self::RRF_K + $position + 1));
            }
        }

        arsort($scores);

        return array_keys($scores);
    }

    // --- facets ----------------------------------------------------------------

    /**
     * @param  Builder<Product>  $base
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function categoryFacet(Builder $base): array
    {
        $counts = (clone $base)
            ->getQuery()
            ->select('primary_category_id')
            ->selectRaw('count(distinct products.id) as total')
            ->groupBy('primary_category_id')
            ->pluck('total', 'primary_category_id');

        if ($counts->isEmpty()) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(static fn (Category $category): array => [
                'value' => $category->slug,
                'label' => $category->name,
                'count' => (int) $counts[$category->getKey()],
            ])
            ->all();
    }

    /**
     * @param  Builder<Product>  $base
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function styleFacet(Builder $base): array
    {
        return (clone $base)
            ->getQuery()
            ->join('styles', 'styles.id', '=', 'products.style_id')
            ->select('styles.code', 'styles.name')
            ->selectRaw('count(distinct products.id) as total')
            ->groupBy('styles.code', 'styles.name')
            ->orderBy('styles.name')
            ->get()
            ->map(static fn (object $row): array => [
                'value' => (string) $row->code,
                'label' => (string) $row->name,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Price bands, in minor units.
     *
     * Fixed bands rather than quantiles. Quantiles produce five equally-full buckets whose
     * boundaries move every time the catalogue does, so a customer who filters "5.000–
     * 10.000" today and returns tomorrow finds a different filter. Round numbers are
     * predictable, and predictable is what a filter needs to be.
     *
     * @param  Builder<Product>  $base
     * @return array<int, array{value: string, label: string, count: int}>
     */
    private function priceFacet(Builder $base): array
    {
        $bands = [
            ['0-500000', 'Bandın altı: 5.000 ₺', 0, 500_000],
            ['500000-1500000', '5.000 – 15.000 ₺', 500_000, 1_500_000],
            ['1500000-4000000', '15.000 – 40.000 ₺', 1_500_000, 4_000_000],
            ['4000000-', '40.000 ₺ üzeri', 4_000_000, null],
        ];

        $facet = [];

        foreach ($bands as [$value, $label, $from, $to]) {
            $count = (clone $base)
                ->whereHas('skus', function (Builder $skus) use ($from, $to): void {
                    /** @var Builder<ProductSku> $skus */
                    $skus->purchasable()
                        ->whereRaw('COALESCE(sale_price_minor, list_price_minor) >= ?', [$from]);

                    if ($to !== null) {
                        $skus->whereRaw('COALESCE(sale_price_minor, list_price_minor) < ?', [$to]);
                    }
                })
                ->count();

            /*
             * Empty bands are dropped rather than shown at zero. A filter that returns
             * nothing is a filter that should not have been offered, and four of them in a
             * row teaches somebody the filters do not work.
             */
            if ($count > 0) {
                $facet[] = ['value' => $value, 'label' => $label, 'count' => $count];
            }
        }

        return $facet;
    }
}

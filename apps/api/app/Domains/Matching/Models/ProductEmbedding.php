<?php

declare(strict_types=1);

namespace App\Domains\Matching\Models;

use App\Domains\Matching\Enums\EmbeddingSource;
use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Products\Models\Product;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One product, as a point in space.
 *
 * The vector column is deliberately absent from `$fillable` and from the casts: pgvector
 * has no Eloquent type, and a 768-element array round-tripping through the model layer on
 * every read would be a real cost for something nothing in PHP ever looks at. It is
 * written with a raw expression by {@see ProductEmbedder}
 * and read only by the database, inside a similarity search.
 *
 * `content_hash` is what keeps re-embedding cheap. A nightly pass over the catalogue that
 * re-embedded every product would cost real money to learn nothing; hashing what went in
 * means only changed descriptions are sent again.
 *
 * `model` is stored beside every vector because vectors from two models are not
 * comparable — the numbers line up and the meaning does not. Keeping it makes a mixed
 * catalogue detectable rather than quietly wrong.
 *
 * @property string $id
 * @property string $product_id
 * @property EmbeddingSource $source
 * @property string $model
 * @property string $content_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductEmbedding extends Model
{
    use HasUuidV7;

    protected $table = 'product_embeddings';

    /** @var list<string> */
    protected $fillable = ['product_id', 'source', 'model', 'content_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source' => EmbeddingSource::class];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOfSource(Builder $query, EmbeddingSource $source): void
    {
        $query->where('source', $source->value);
    }

    /**
     * Formats a vector the way PostgreSQL wants to read it.
     *
     * `[0.1,0.2,…]` — no spaces, full precision. Kept here rather than in the service
     * because the *format* is a property of the column, and a second caller formatting it
     * slightly differently is a bug that shows up as a parse error at three in the morning.
     *
     * @param  array<int, float>  $vector
     */
    public static function toVectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.') ?: '0',
            $vector,
        )).']';
    }
}

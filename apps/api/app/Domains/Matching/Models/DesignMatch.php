<?php

declare(strict_types=1);

namespace App\Domains\Matching\Models;

use App\Domains\Matching\Enums\MatchStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Projects\Models\DesignVersion;
use App\Support\Casts\MoneyCast;
use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One product suggested for one place in a design.
 *
 * The price is a snapshot, and that word matters. A customer who opens their design next
 * week must see the list they were shown rather than a page that silently repriced itself
 * — and when the catalogue has moved, the difference is the thing worth telling them
 * about. What they actually pay is settled at checkout; this is a record of a
 * recommendation, not an offer.
 *
 * The score is kept in three parts because they answer different questions. The total
 * decides the order; `similarity_bps` and `rerank_bps` are what somebody reads when the
 * order looks wrong, and without them "why did it suggest this" has no answer but a shrug.
 *
 * @property string $id
 * @property string $design_version_id
 * @property int $placement_index
 * @property string $placement_category
 * @property string $product_id
 * @property string $sku_id
 * @property int $rank
 * @property int $score_bps
 * @property int|null $similarity_bps
 * @property int|null $rerank_bps
 * @property string|null $reason
 * @property Money $price_minor
 * @property string $currency
 * @property MatchStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignMatch extends Model
{
    use HasUuidV7;

    protected $table = 'design_matches';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'suggested', 'currency' => 'TRY'];

    /** @var list<string> */
    protected $fillable = [
        'design_version_id',
        'placement_index',
        'placement_category',
        'product_id',
        'sku_id',
        'rank',
        'score_bps',
        'similarity_bps',
        'rerank_bps',
        'reason',
        'price_minor',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement_index' => 'integer',
            'rank' => 'integer',
            'score_bps' => 'integer',
            'similarity_bps' => 'integer',
            'rerank_bps' => 'integer',
            'price_minor' => MoneyCast::class.':currency',
            'status' => MatchStatus::class,
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** @return HasMany<DesignMatchFeedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(DesignMatchFeedback::class, 'match_id');
    }

    /**
     * Whether the offer still costs what the customer was shown.
     *
     * The price that matters at checkout is today's, so a difference is not an error — it
     * is the one thing about this row worth pointing out.
     */
    public function priceHasMoved(): bool
    {
        $this->loadMissing('sku');

        $current = $this->sku?->effectivePrice();

        return $current !== null && $current->amountMinor !== $this->price_minor->amountMinor;
    }

    /** @param  Builder<$this>  $query */
    public function scopeForVersion(Builder $query, string $versionId): void
    {
        $query->where('design_version_id', $versionId)
            ->orderBy('placement_index')
            ->orderBy('rank');
    }

    /** @param  Builder<$this>  $query */
    public function scopeChosen(Builder $query): void
    {
        $query->where('status', MatchStatus::Accepted->value);
    }
}

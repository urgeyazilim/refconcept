<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Products\Models\ProductSku;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The balance of one SKU at one location.
 *
 * A snapshot, not the truth. The truth is {@see StockMovement}, and this row is
 * derived from it — kept up to date inside the same locked transaction that writes
 * the movement, so the two cannot drift. Reading stock is far more frequent than
 * changing it, and summing a ledger on every catalogue page is not a trade worth
 * making.
 *
 * `sellable` is deliberately a method rather than a column. A stored third number is
 * a third thing that can be wrong.
 *
 * @property string $id
 * @property string $sku_id
 * @property string $location_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $reorder_point
 * @property Carbon|null $counted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StockItem extends Model
{
    use HasUuidV7;

    protected $table = 'stock_items';

    /** @var array<string, mixed> */
    protected $attributes = [
        'on_hand' => 0,
        'reserved' => 0,
        'reorder_point' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'sku_id',
        'location_id',
        'on_hand',
        'reserved',
        'reorder_point',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'reorder_point' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderByDesc('created_at');
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** What can still be promised to a customer. */
    public function sellable(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    public function isBelowReorderPoint(): bool
    {
        return $this->reorder_point > 0 && $this->sellable() <= $this->reorder_point;
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSku(Builder $query, string $skuId): void
    {
        $query->where('sku_id', $skuId);
    }

    /**
     * Rows that need the seller's attention.
     *
     * Expressed in SQL rather than by filtering a collection, because the whole point
     * is to find the handful of rows among thousands without loading thousands.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNeedsAttention(Builder $query): void
    {
        $query->where('reorder_point', '>', 0)
            ->whereRaw('(on_hand - reserved) <= reorder_point');
    }
}

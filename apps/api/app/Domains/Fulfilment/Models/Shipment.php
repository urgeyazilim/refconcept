<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Models;

use App\Domains\Orders\Models\SellerOrder;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One parcel.
 *
 * A seller order can have several: a sofa and its cushions leave on different days, and
 * "shipped" is therefore a property of a parcel rather than of an order.
 *
 * @property string $id
 * @property string $seller_order_id
 * @property string|null $carrier
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property string $status
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property string|null $note
 * @property-read SellerOrder|null $sellerOrder
 * @property-read Collection<int, ShipmentItem> $items
 */
class Shipment extends Model
{
    use HasUuidV7;

    protected $table = 'shipments';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'preparing'];

    /** @var list<string> */
    protected $fillable = [
        'seller_order_id',
        'carrier',
        'tracking_number',
        'tracking_url',
        'status',
        'shipped_at',
        'delivered_at',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<SellerOrder, $this> */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    /** @return HasMany<ShipmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->loadMissing('items');

        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'status' => $this->status,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'note' => $this->note,
            'item_count' => (int) $this->items->sum('quantity'),
        ];
    }
}

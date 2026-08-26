<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Models;

use App\Domains\Orders\Models\OrderItem;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one order line went in one parcel.
 *
 * A line can be split across parcels — three of four chairs today, the fourth when it
 * arrives — which is why the quantity lives here rather than being implied.
 *
 * @property string $id
 * @property string $shipment_id
 * @property string $order_item_id
 * @property int $quantity
 */
class ShipmentItem extends Model
{
    use HasUuidV7;

    protected $table = 'shipment_items';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['shipment_id', 'order_item_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}

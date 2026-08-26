<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Models;

use App\Domains\Orders\Models\OrderItem;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line inside a return.
 *
 * `quantity` is what the customer asked to send back; `approved_quantity` is what the
 * seller accepted. The two being separate is the entire reason partial returns work — a
 * customer sends three chairs, one arrives damaged in transit, and the record can say so.
 *
 * The unit price is snapshotted from the order line, because what a refund is calculated
 * from must not move when a seller reprices.
 *
 * @property string $id
 * @property string $return_id
 * @property string $order_item_id
 * @property int $quantity
 * @property int $approved_quantity
 * @property int $unit_price_minor
 * @property int $refund_minor
 * @property int $commission_rate_bps
 * @property string|null $condition_note
 * @property-read OrderItem|null $orderItem
 */
class ReturnItem extends Model
{
    use HasUuidV7;

    protected $table = 'return_items';

    /** @var array<string, mixed> */
    protected $attributes = ['approved_quantity' => 0, 'refund_minor' => 0];

    /** @var list<string> */
    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
        'approved_quantity',
        'unit_price_minor',
        'refund_minor',
        'commission_rate_bps',
        'condition_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'approved_quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'refund_minor' => 'integer',
            'commission_rate_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->loadMissing('orderItem');

        return [
            'id' => $this->id,
            'product_name' => $this->orderItem?->product_name,
            'quantity' => $this->quantity,
            'approved_quantity' => $this->approved_quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'refund_minor' => $this->refund_minor,
            'condition_note' => $this->condition_note,
        ];
    }
}

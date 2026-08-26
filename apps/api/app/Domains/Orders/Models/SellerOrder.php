<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Fulfilment\Models\Shipment;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One seller's part of an order: their parcel, their warehouse, their money.
 *
 * @property string $id
 * @property string $order_id
 * @property string $seller_id
 * @property string $seller_order_number
 * @property SellerOrderStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property int $commission_minor
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Order|null $order
 * @property-read Seller|null $seller
 * @property-read Collection<int, OrderItem> $items
 */
class SellerOrder extends Model
{
    use HasUuidV7;

    protected $table = 'seller_orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'awaiting_confirmation',
        'currency' => 'TRY',
        'tax_minor' => 0,
        'shipping_minor' => 0,
        'commission_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'seller_id',
        'seller_order_number',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'shipping_minor',
        'total_minor',
        'commission_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SellerOrderStatus::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'commission_minor' => 'integer',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'seller_order_id');
    }

    /** @return HasMany<OrderStatusChange, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class, 'seller_order_id');
    }

    /** @return HasMany<ReturnRequest, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRequest::class, 'seller_order_id');
    }

    /** @return HasMany<Shipment, $this> */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'seller_order_id');
    }

    /** What the seller keeps once the platform's cut is taken. */
    public function payableMinor(): int
    {
        return max(0, $this->total_minor - $this->commission_minor);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            SellerOrderStatus::AwaitingConfirmation->value,
            SellerOrderStatus::Confirmed->value,
            SellerOrderStatus::Preparing->value,
        ]);
    }

    /**
     * What the seller sees.
     *
     * The customer's name and the delivery address come from the master order, because a
     * courier label needs them — but nothing about the customer's other sellers does. A
     * seller has no business knowing who else is in the basket.
     *
     * @return array<string, mixed>
     */
    public function toSellerArray(bool $withItems = false): array
    {
        $this->loadMissing('order');

        $payload = [
            'id' => $this->id,
            'seller_order_number' => $this->seller_order_number,
            'order_number' => $this->order?->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'tax_minor' => $this->tax_minor,
            'total_minor' => $this->total_minor,
            'commission_minor' => $this->commission_minor,
            'payable_minor' => $this->payableMinor(),
            'item_count' => (int) $this->items()->sum('quantity'),
            'placed_at' => $this->order?->placed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'next_statuses' => array_map(
                static fn (SellerOrderStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                $this->status->allowedNext(),
            ),
        ];

        if (! $withItems) {
            return $payload;
        }

        $this->loadMissing('items');

        return $payload + [
            'recipient' => [
                'name' => $this->order?->shipping_address['recipient_name'] ?? null,
                'phone' => $this->order?->shipping_address['phone'] ?? null,
                'city' => $this->order?->shipping_address['city'] ?? null,
                'district' => $this->order?->shipping_address['district'] ?? null,
                'address_line1' => $this->order?->shipping_address['address_line1'] ?? null,
                'address_line2' => $this->order?->shipping_address['address_line2'] ?? null,
                'postal_code' => $this->order?->shipping_address['postal_code'] ?? null,
            ],
            'cancellation_reason' => $this->cancellation_reason,
            'items' => $this->items->map(fn (OrderItem $item): array => $item->toArray())->values()->all(),
        ];
    }
}

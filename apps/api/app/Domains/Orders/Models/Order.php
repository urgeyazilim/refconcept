<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Payments\Models\CheckoutSession;
use App\Domains\Payments\Models\PaymentIntent;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The customer's order: one payment, one number, one place to ask about it.
 *
 * Its status is derived from its seller orders rather than written directly — see
 * {@see OrderStatus::fromSellerOrders()}. A summary that can be set independently of what
 * it summarises will eventually disagree with it.
 *
 * @property string $id
 * @property string $order_number
 * @property string $user_id
 * @property string $checkout_session_id
 * @property string|null $payment_intent_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $shipping_minor
 * @property int $tax_minor
 * @property int $grand_total_minor
 * @property array<string, mixed> $shipping_address
 * @property array<string, mixed> $billing_address
 * @property string|null $customer_email
 * @property string|null $customer_note
 * @property Carbon $placed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $completed_at
 * @property-read User|null $customer
 * @property-read CheckoutSession|null $session
 * @property-read PaymentIntent|null $payment
 * @property-read Collection<int, SellerOrder> $sellerOrders
 * @property-read Collection<int, OrderItem> $items
 */
class Order extends Model
{
    use HasUuidV7;

    protected $table = 'orders';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'paid',
        'currency' => 'TRY',
        'discount_minor' => 0,
        'shipping_minor' => 0,
        'tax_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_number',
        'user_id',
        'checkout_session_id',
        'payment_intent_id',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'shipping_minor',
        'tax_minor',
        'grand_total_minor',
        'shipping_address',
        'billing_address',
        'customer_email',
        'customer_note',
        'placed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'tax_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return HasMany<SellerOrder, $this> */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'order_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** @return HasMany<OrderStatusChange, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class, 'order_id');
    }

    public function itemCount(): int
    {
        $this->loadMissing('items');

        return (int) $this->items->sum('quantity');
    }

    /** @param  Builder<$this>  $query */
    public function scopeForCustomer(Builder $query, string $userId): void
    {
        $query->where('user_id', $userId)->orderByDesc('placed_at');
    }

    /**
     * What a customer sees.
     *
     * Grouped by seller, because that is how it will arrive: several parcels from several
     * shops on several days. A flat list would be a promise the delivery cannot keep.
     *
     * @return array<string, mixed>
     */
    public function toCustomerArray(bool $withItems = false): array
    {
        $this->loadMissing(['sellerOrders.seller', 'items']);

        $payload = [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'item_count' => $this->itemCount(),
            'totals' => [
                'subtotal_minor' => $this->subtotal_minor,
                'discount_minor' => $this->discount_minor,
                'shipping_minor' => $this->shipping_minor,
                'tax_minor' => $this->tax_minor,
                'grand_total_minor' => $this->grand_total_minor,
            ],
            'placed_at' => $this->placed_at->toIso8601String(),
        ];

        if (! $withItems) {
            return $payload;
        }

        return $payload + [
            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,
            'customer_note' => $this->customer_note,
            'sellers' => $this->sellerOrders->map(fn (SellerOrder $sellerOrder): array => [
                'id' => $sellerOrder->id,
                'seller_order_number' => $sellerOrder->seller_order_number,
                'seller_name' => $sellerOrder->seller?->display_name,
                // The customer's vocabulary, not the seller's: "onay bekliyor" is an
                // internal state and reads as a problem to somebody who has already paid.
                'status' => $sellerOrder->status->value,
                'status_label' => $sellerOrder->status->customerLabel(),
                'total_minor' => $sellerOrder->total_minor,
                'shipped_at' => $sellerOrder->shipped_at?->toIso8601String(),
                'delivered_at' => $sellerOrder->delivered_at?->toIso8601String(),
                'items' => $this->items
                    ->where('seller_order_id', $sellerOrder->getKey())
                    ->map(fn (OrderItem $item): array => $item->toArray())
                    ->values()
                    ->all(),
            ])->all(),
        ];
    }
}

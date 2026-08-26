<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\RefundStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Payments\Models\PaymentIntent;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money going back.
 *
 * Its own lifecycle, separate from the return that usually causes it. Goods and money
 * travel on different timetables: a provider can refuse a refund on a payment that is too
 * old, a bank can take days, and a goodwill refund has no return behind it at all.
 *
 * The split between the seller's share and the commission is stored rather than
 * recomputed, so the reversing journal entry can be posted from what was decided at the
 * time even if rates or orders have moved since.
 *
 * @property string $id
 * @property string $reference
 * @property string $order_id
 * @property string|null $seller_order_id
 * @property string|null $return_id
 * @property string|null $payment_intent_id
 * @property RefundStatus $status
 * @property string $currency
 * @property int $amount_minor
 * @property int $seller_share_minor
 * @property int $commission_share_minor
 * @property string|null $reason
 * @property string|null $failure_reason
 * @property string|null $created_by
 * @property Carbon|null $processed_at
 * @property-read Order|null $order
 * @property-read SellerOrder|null $sellerOrder
 * @property-read ReturnRequest|null $returnRequest
 * @property-read PaymentIntent|null $payment
 */
class Refund extends Model
{
    use HasUuidV7;

    protected $table = 'refunds';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'TRY',
        'seller_share_minor' => 0,
        'commission_share_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'order_id',
        'seller_order_id',
        'return_id',
        'payment_intent_id',
        'currency',
        'amount_minor',
        'seller_share_minor',
        'commission_share_minor',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'seller_share_minor' => 'integer',
            'commission_share_minor' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<SellerOrder, $this> */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    /** @return BelongsTo<ReturnRequest, $this> */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_id');
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            RefundStatus::Pending->value,
            RefundStatus::Processing->value,
            RefundStatus::Failed->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->status->customerMessage(),
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'seller_share_minor' => $this->seller_share_minor,
            'commission_share_minor' => $this->commission_share_minor,
            'reason' => $this->reason,
            // The provider's own words, kept for finance rather than shown to a customer.
            'failure_reason' => $this->failure_reason,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

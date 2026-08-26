<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Models;

use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer wanting to send something back.
 *
 * Named `ReturnRequest` rather than `Return` because `Return` is a reserved word in PHP
 * and a class you cannot type-hint is worse than an awkward name.
 *
 * Per seller order, because that is who receives the parcel: a return spanning three
 * sellers is three returns, however it looked when the customer pressed the button.
 *
 * @property string $id
 * @property string $reference
 * @property string $order_id
 * @property string $seller_order_id
 * @property string|null $requested_by
 * @property ReturnStatus $status
 * @property string $reason_code
 * @property string|null $reason_note
 * @property string $currency
 * @property int $requested_minor
 * @property int $approved_minor
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $received_at
 * @property-read Order|null $order
 * @property-read SellerOrder|null $sellerOrder
 * @property-read Collection<int, ReturnItem> $items
 */
class ReturnRequest extends Model
{
    use HasUuidV7;

    protected $table = 'returns';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'requested',
        'currency' => 'TRY',
        'requested_minor' => 0,
        'approved_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'order_id',
        'seller_order_id',
        'requested_by',
        'reason_code',
        'reason_note',
        'currency',
        'requested_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'requested_minor' => 'integer',
            'approved_minor' => 'integer',
            'decided_at' => 'datetime',
            'received_at' => 'datetime',
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

    /** @return HasMany<ReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'return_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Returns that stop a seller order being paid out.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', [
            ReturnStatus::Requested->value,
            ReturnStatus::Approved->value,
            ReturnStatus::InTransit->value,
            ReturnStatus::Received->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->loadMissing(['items', 'sellerOrder.seller', 'refunds']);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->status->customerMessage(),
            'reason_code' => $this->reason_code,
            'reason_note' => $this->reason_note,
            'currency' => $this->currency,
            'requested_minor' => $this->requested_minor,
            'approved_minor' => $this->approved_minor,
            'seller_order_number' => $this->sellerOrder?->seller_order_number,
            'seller_name' => $this->sellerOrder?->seller?->display_name,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->items->map(fn (ReturnItem $item): array => $item->toArray())->values()->all(),
            'refund' => $this->refunds->sortByDesc('created_at')->first()?->toArray(),
        ];
    }
}

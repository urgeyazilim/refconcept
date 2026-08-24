<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\ReservationStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A hold on stock that has not been paid for yet.
 *
 * The reference is what the hold is *for* — a basket, an order, a design proposal —
 * and a partial unique index guarantees one live hold per reference per stock item.
 * That is what makes reserving idempotent: a checkout retried after a timeout does
 * not take the last two sofas twice.
 *
 * @property string $id
 * @property string $stock_item_id
 * @property int $quantity
 * @property ReservationStatus $status
 * @property string $reference_type
 * @property string $reference_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $released_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StockReservation extends Model
{
    use HasUuidV7;

    protected $table = 'stock_reservations';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'held',
    ];

    /** @var list<string> */
    protected $fillable = [
        'stock_item_id',
        'quantity',
        'status',
        'reference_type',
        'reference_id',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function hasExpired(): bool
    {
        return $this->status === ReservationStatus::Held
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /** @param  Builder<$this>  $query */
    public function scopeHeld(Builder $query): void
    {
        $query->where('status', ReservationStatus::Held->value);
    }

    /**
     * Holds whose time is up.
     *
     * A reservation with no expiry never appears here on purpose: an order that has
     * been paid for holds its stock until it ships, however long that takes.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', ReservationStatus::Held->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}

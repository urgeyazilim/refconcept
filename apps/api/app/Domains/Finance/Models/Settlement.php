<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\SettlementStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payout run for one seller over one period.
 *
 * @property string $id
 * @property string $reference
 * @property string $seller_id
 * @property SettlementStatus $status
 * @property string $currency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $gross_minor
 * @property int $commission_minor
 * @property int $adjustment_minor
 * @property int $net_minor
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $paid_by
 * @property Carbon|null $paid_at
 * @property string|null $payout_reference
 * @property string|null $note
 * @property-read Seller|null $seller
 * @property-read Collection<int, SettlementItem> $items
 */
class Settlement extends Model
{
    use HasUuidV7;

    protected $table = 'settlements';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'TRY',
        'gross_minor' => 0,
        'commission_minor' => 0,
        'adjustment_minor' => 0,
        'net_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'seller_id',
        'currency',
        'period_start',
        'period_end',
        'gross_minor',
        'commission_minor',
        'adjustment_minor',
        'net_minor',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_minor' => 'integer',
            'commission_minor' => 'integer',
            'adjustment_minor' => 'integer',
            'net_minor' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return HasMany<SettlementItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class, 'settlement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [SettlementStatus::Draft->value, SettlementStatus::Approved->value]);
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
            'currency' => $this->currency,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'gross_minor' => $this->gross_minor,
            'commission_minor' => $this->commission_minor,
            'adjustment_minor' => $this->adjustment_minor,
            'net_minor' => $this->net_minor,
            'item_count' => $this->items()->count(),
            'seller_name' => $this->seller?->display_name,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payout_reference' => $this->payout_reference,
            'note' => $this->note,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Domains\Credits\Enums\CreditLotSource;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One batch of credits, with one expiry date.
 *
 * Lots exist because a balance cannot expire — a *grant* can. Fifty credits bought in
 * March and ten from a promotion in June are the same number in a wallet and two
 * different deadlines, and only a batch can carry the second.
 *
 * They are consumed soonest-deadline-first. Spending the non-expiring credits first
 * would quietly destroy the ones with a date on them, and the customer would discover it
 * as a balance that dropped for no reason they can see.
 *
 * @property string $id
 * @property string $wallet_id
 * @property CreditLotSource $source
 * @property int $amount
 * @property int $remaining
 * @property Carbon|null $expires_at
 * @property Carbon|null $exhausted_at
 * @property string|null $origin_type
 * @property string|null $origin_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditLot extends Model
{
    use HasUuidV7;

    protected $table = 'credit_lots';

    /** @var list<string> */
    protected $fillable = [
        'wallet_id',
        'source',
        'amount',
        'remaining',
        'expires_at',
        'origin_type',
        'origin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CreditLotSource::class,
            'amount' => 'integer',
            'remaining' => 'integer',
            'expires_at' => 'datetime',
            'exhausted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditWallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'wallet_id');
    }

    public function hasExpired(?Carbon $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte($at ?? now());
    }

    /**
     * Lots that can still be spent from, in the order they should be.
     *
     * `expires_at NULLS LAST` is the whole rule in one clause: dated credits go first,
     * undated ones are the reserve. Ties break on the lot id, which is a UUIDv7 and
     * therefore time-ordered — so two lots expiring on the same day are spent oldest
     * first, and the order is stable rather than whatever the planner felt like.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSpendable(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('remaining', '>', 0)
            ->where(function (Builder $inner) use ($at): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $at);
            })
            ->orderByRaw('expires_at ASC NULLS LAST')
            ->orderBy('id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeExpiring(Builder $query, Carbon $before): void
    {
        $query->where('remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $before);
    }
}

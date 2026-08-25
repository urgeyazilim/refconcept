<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer's credit balance.
 *
 * A snapshot, never the authority. Everything here can be recomputed from
 * {@see CreditTransaction} and {@see CreditLot}, and when the two ever disagree the
 * ledger is right — which is why {@see CreditLedger} writes both inside one locked
 * transaction and nothing else writes this table at all.
 *
 * @property string $id
 * @property string $user_id
 * @property int $balance
 * @property int $reserved
 * @property int $lifetime_purchased
 * @property int $lifetime_granted
 * @property int $lifetime_consumed
 * @property int $lifetime_expired
 * @property Carbon|null $last_movement_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditWallet extends Model
{
    use HasUuidV7;

    protected $table = 'credit_wallets';

    /** @var array<string, mixed> */
    protected $attributes = [
        'balance' => 0,
        'reserved' => 0,
        'lifetime_purchased' => 0,
        'lifetime_granted' => 0,
        'lifetime_consumed' => 0,
        'lifetime_expired' => 0,
    ];

    /** @var list<string> */
    protected $fillable = ['user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved' => 'integer',
            'lifetime_purchased' => 'integer',
            'lifetime_granted' => 'integer',
            'lifetime_consumed' => 'integer',
            'lifetime_expired' => 'integer',
            'last_movement_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'wallet_id')->orderByDesc('id');
    }

    /** @return HasMany<CreditLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(CreditLot::class, 'wallet_id');
    }

    /** @return HasMany<CreditReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class, 'wallet_id');
    }

    /**
     * What can actually be spent right now.
     *
     * Computed rather than stored. A stored `available` would be a third number that has
     * to agree with the other two, and the day it does not is the day a customer is told
     * they cannot afford something they have paid for.
     */
    public function available(): int
    {
        return $this->balance - $this->reserved;
    }

    public function canAfford(int $credits): bool
    {
        return $this->available() >= $credits;
    }
}

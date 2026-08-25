<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Domains\Credits\Enums\CreditTransactionType;
use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement of credits. The authority for all of them.
 *
 * Append-only, and enforced by a database trigger rather than by this class — an
 * Eloquent guard is a suggestion that a raw query walks straight past, and this is the
 * table a customer's complaint gets settled against. A mistake is corrected with a
 * compensating entry, the way a mistake in any ledger is corrected.
 *
 * `balance_after` is stored rather than recomputed. A statement a customer disputes has
 * to show the balance as it stood *then*, not as today's code would calculate it from
 * the beginning of time.
 *
 * @property string $id
 * @property string $wallet_id
 * @property CreditTransactionType $type
 * @property int $amount
 * @property int $balance_after
 * @property int $reserved_after
 * @property string|null $lot_id
 * @property string|null $reservation_id
 * @property string $description
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $actor_id
 * @property string|null $reason
 * @property string|null $reference
 * @property Carbon $created_at
 */
class CreditTransaction extends Model
{
    use HasUuidV7;

    protected $table = 'credit_transactions';

    /** A row that records a moment has no updated_at worth keeping. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_after',
        'reserved_after',
        'lot_id',
        'reservation_id',
        'description',
        'subject_type',
        'subject_id',
        'actor_id',
        'reason',
        'reference',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'reserved_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditWallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'wallet_id');
    }

    /** @return BelongsTo<CreditLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(CreditLot::class, 'lot_id');
    }

    /** @return BelongsTo<CreditReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'reservation_id');
    }

    /**
     * Who did this, when it was not the customer themselves.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The statement a customer reads.
     *
     * Holds are excluded: a reserve followed by a consume is one event to the person who
     * ran a render, and three lines for it is how a statement becomes something nobody
     * checks.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNotIn('type', [
            CreditTransactionType::Reserve->value,
            CreditTransactionType::Release->value,
        ]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSince(Builder $query, Carbon $from): void
    {
        $query->where('created_at', '>=', $from);
    }
}

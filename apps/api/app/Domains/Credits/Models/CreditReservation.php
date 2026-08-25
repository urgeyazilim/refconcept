<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Domains\Credits\Enums\ReservationStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credits held for work that has not finished.
 *
 * A row rather than a running total on the wallet, for two reasons that both bite in
 * production. Releasing has to know how much *this* job was holding, and a single
 * counter cannot say. And a request that is retried has to find its existing hold rather
 * than take a second one — which is what `reference` is for, and why it is unique.
 *
 * @property string $id
 * @property string $wallet_id
 * @property int $amount
 * @property ReservationStatus $status
 * @property string $reference
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditReservation extends Model
{
    use HasUuidV7;

    protected $table = 'credit_reservations';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'held'];

    /** @var list<string> */
    protected $fillable = [
        'wallet_id',
        'amount',
        'reference',
        'subject_type',
        'subject_id',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CreditWallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'wallet_id');
    }

    public function isHeld(): bool
    {
        return $this->status === ReservationStatus::Held;
    }

    /**
     * Holds nobody ever came back for.
     *
     * A customer who closes the tab mid-render must not have those credits locked away
     * forever; without a sweep this is a slow leak that ends with somebody unable to
     * spend a balance the screen says they have.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAbandoned(Builder $query, ?Carbon $at = null): void
    {
        $query->where('status', ReservationStatus::Held->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at ?? now());
    }
}

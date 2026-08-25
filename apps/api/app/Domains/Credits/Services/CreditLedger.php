<?php

declare(strict_types=1);

namespace App\Domains\Credits\Services;

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Enums\CreditTransactionType;
use App\Domains\Credits\Enums\ReservationStatus;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditLot;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Models\CreditWallet;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only thing that moves credits.
 *
 * Nothing else writes `credit_wallets`, `credit_lots`, `credit_transactions` or
 * `credit_reservations`. That is the whole design: an aggregate that can be updated from
 * six places is an aggregate that will eventually disagree with its own ledger, and the
 * disagreement is discovered by a customer who says their balance is wrong.
 *
 * Three rules hold everywhere in this class.
 *
 * **Everything takes the row lock, and re-reads inside it.** `SELECT … FOR UPDATE` on the
 * wallet, then decide from what the lock returned — never from a model the caller was
 * holding. A caller's copy was read before the lock and may be describing a balance that
 * no longer exists; deciding from it is exactly how two concurrent renders both find
 * enough credits and one of them spends money that is not there.
 *
 * **Every entry carries the balance it produced.** Recomputing a running total from the
 * start of time is slow, and worse, it means a statement a customer disputes shows what
 * today's code calculates rather than what was true at the time.
 *
 * **A repeat is not an error.** Every mutating method takes a `reference`, and a second
 * call with the same one returns the first result untouched. A client retrying a request
 * whose response it never saw is the normal case, not the exceptional one, and answering
 * it with a second charge is the failure worth engineering against.
 */
final class CreditLedger
{
    /**
     * The wallet, created on first sight.
     *
     * Lazily rather than at registration, so a user who never touches AI never gets a
     * row — and so a wallet cannot be missing at the moment somebody needs to spend.
     */
    public function walletFor(User $user): CreditWallet
    {
        return CreditWallet::query()->firstOrCreate(['user_id' => $user->getKey()]);
    }

    public function balanceFor(User $user): CreditWallet
    {
        return $this->walletFor($user);
    }

    /**
     * Adds credits, as a new lot with its own expiry.
     *
     * The single entry point for every kind of increase — a purchase, a promotion, an
     * admin's goodwill — because they differ only in what the row says about them. A
     * separate method per source would be four places that have to remember to write a
     * lot, and the one that forgot would produce credits that can never expire.
     *
     * @throws InvalidArgumentException when asked to grant nothing
     */
    public function grant(
        User $user,
        int $credits,
        CreditLotSource $source,
        string $description,
        ?string $reference = null,
        ?Carbon $expiresAt = null,
        ?Model $origin = null,
        ?User $actor = null,
        ?string $reason = null,
    ): CreditTransaction {
        if ($credits <= 0) {
            throw new InvalidArgumentException('Kredi tanımlama tutarı sıfırdan büyük olmalı.');
        }

        return DB::transaction(function () use (
            $user, $credits, $source, $description, $reference, $expiresAt, $origin, $actor, $reason
        ): CreditTransaction {
            $existing = $this->findByReference($reference);

            if ($existing !== null) {
                return $existing;
            }

            $wallet = $this->lock($user);

            $lot = CreditLot::query()->create([
                'wallet_id' => $wallet->getKey(),
                'source' => $source,
                'amount' => $credits,
                'remaining' => $credits,
                'expires_at' => $expiresAt,
                'origin_type' => $origin !== null ? $origin::class : null,
                'origin_id' => $origin?->getKey(),
            ]);

            $wallet->balance += $credits;

            $this->bumpLifetime($wallet, $source, $credits);

            $wallet->last_movement_at = now();
            $wallet->save();

            return $this->record(
                wallet: $wallet,
                type: $source->transactionType(),
                amount: $credits,
                description: $description,
                lotId: $lot->getKey(),
                reference: $reference,
                subject: $origin,
                actor: $actor,
                reason: $reason,
            );
        });
    }

    /**
     * Holds credits for work that has not happened yet.
     *
     * Nothing is spent and the balance does not change; only the line between held and
     * available moves. The hold is what makes it safe to start a render before knowing
     * whether it will succeed — and what makes it possible to give the credits back
     * cleanly when it does not.
     *
     * @throws InsufficientCredits
     */
    public function reserve(
        User $user,
        int $credits,
        string $reference,
        string $description,
        ?Model $subject = null,
        ?Carbon $expiresAt = null,
    ): CreditReservation {
        if ($credits <= 0) {
            throw new InvalidArgumentException('Bloke tutarı sıfırdan büyük olmalı.');
        }

        return DB::transaction(function () use ($user, $credits, $reference, $description, $subject, $expiresAt): CreditReservation {
            /*
             * The idempotency check happens inside the transaction, after the lock, so
             * two simultaneous retries of the same request cannot both miss it. Outside
             * the lock this would be a race that produces two holds for one job.
             */
            $wallet = $this->lock($user);

            $existing = CreditReservation::query()
                ->where('reference', $reference)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Decided from the locked row, never from anything the caller was holding.
            if ($wallet->available() < $credits) {
                throw InsufficientCredits::forReservation($credits, $wallet->available());
            }

            $reservation = CreditReservation::query()->create([
                'wallet_id' => $wallet->getKey(),
                'amount' => $credits,
                'reference' => $reference,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                // A hold with no deadline is a leak: a customer who closes the tab
                // mid-render would have those credits locked away forever.
                'expires_at' => $expiresAt ?? now()->addHours(2),
            ]);

            $wallet->reserved += $credits;
            $wallet->last_movement_at = now();
            $wallet->save();

            $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Reserve,
                amount: 0,
                description: $description,
                reservationId: $reservation->getKey(),
                subject: $subject,
            );

            return $reservation;
        });
    }

    /**
     * Turns a hold into a spend.
     *
     * The credits come out of lots, soonest-deadline-first, so nobody loses credits they
     * could have used. Idempotent on the reservation's own state rather than on a
     * reference: a reservation that is no longer held has already been settled, and
     * settling it again would spend the same credits twice.
     */
    public function consume(CreditReservation $reservation, ?string $description = null): CreditReservation
    {
        return DB::transaction(function () use ($reservation, $description): CreditReservation {
            $held = CreditReservation::query()->lockForUpdate()->find($reservation->getKey());

            if ($held === null || ! $held->isHeld()) {
                return $held ?? $reservation;
            }

            $wallet = $this->lockWallet($held->wallet_id);

            $this->drawFromLots($wallet, $held->amount);

            $wallet->balance -= $held->amount;
            $wallet->reserved -= $held->amount;
            $wallet->lifetime_consumed += $held->amount;
            $wallet->last_movement_at = now();
            $wallet->save();

            $held->forceFill([
                'status' => ReservationStatus::Consumed,
                'settled_at' => now(),
            ])->save();

            $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Consume,
                amount: -$held->amount,
                description: $description ?? 'Kredi kullanımı',
                reservationId: $held->getKey(),
                subjectType: $held->subject_type,
                subjectId: $held->subject_id,
            );

            return $held;
        });
    }

    /**
     * Gives a hold back.
     *
     * The path a failed render takes. Also idempotent on the reservation's state, for the
     * same reason as consume: a job that fails and is then swept as abandoned must not
     * return the credits twice.
     */
    public function release(
        CreditReservation $reservation,
        string $description = 'Bloke çözüldü',
        ReservationStatus $as = ReservationStatus::Released,
    ): CreditReservation {
        return DB::transaction(function () use ($reservation, $description, $as): CreditReservation {
            $held = CreditReservation::query()->lockForUpdate()->find($reservation->getKey());

            if ($held === null || ! $held->isHeld()) {
                return $held ?? $reservation;
            }

            $wallet = $this->lockWallet($held->wallet_id);

            $wallet->reserved -= $held->amount;
            $wallet->last_movement_at = now();
            $wallet->save();

            $held->forceFill(['status' => $as, 'settled_at' => now()])->save();

            $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Release,
                amount: 0,
                description: $description,
                reservationId: $held->getKey(),
                subjectType: $held->subject_type,
                subjectId: $held->subject_id,
            );

            return $held;
        });
    }

    /**
     * Spends credits with no prior hold.
     *
     * For work that is instantaneous and cannot fail halfway — there is nothing to give
     * back, so a hold would be ceremony. Anything a provider might time out on should
     * reserve first.
     *
     * @throws InsufficientCredits
     */
    public function spend(
        User $user,
        int $credits,
        string $description,
        string $reference,
        ?Model $subject = null,
    ): CreditTransaction {
        if ($credits <= 0) {
            throw new InvalidArgumentException('Harcama tutarı sıfırdan büyük olmalı.');
        }

        return DB::transaction(function () use ($user, $credits, $description, $reference, $subject): CreditTransaction {
            $wallet = $this->lock($user);

            $existing = $this->findByReference($reference);

            if ($existing !== null) {
                return $existing;
            }

            if ($wallet->available() < $credits) {
                throw InsufficientCredits::forSpend($credits, $wallet->available());
            }

            $this->drawFromLots($wallet, $credits);

            $wallet->balance -= $credits;
            $wallet->lifetime_consumed += $credits;
            $wallet->last_movement_at = now();
            $wallet->save();

            return $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Consume,
                amount: -$credits,
                description: $description,
                reference: $reference,
                subject: $subject,
            );
        });
    }

    /**
     * A correction, in either direction, by a member of staff.
     *
     * The only movement that demands a reason — enforced by a CHECK constraint, not by
     * this method — because it is the only one that happens because a person decided it
     * should. "Why do I have forty fewer credits than yesterday" has to have an answer
     * that is not "somebody ran a script".
     *
     * A positive adjustment creates a lot like any other grant. A negative one draws from
     * lots like a spend, and is refused if the wallet cannot cover it: an adjustment that
     * could drive a balance negative would be a way to bill somebody for nothing.
     *
     * @throws InsufficientCredits
     */
    public function adjust(
        User $user,
        int $delta,
        string $reason,
        User $actor,
        ?string $reference = null,
    ): CreditTransaction {
        if ($delta === 0) {
            throw new InvalidArgumentException('Düzeltme tutarı sıfır olamaz.');
        }

        if ($delta > 0) {
            return $this->grant(
                user: $user,
                credits: $delta,
                source: CreditLotSource::Adjustment,
                description: 'Manuel düzeltme',
                reference: $reference,
                actor: $actor,
                reason: $reason,
            );
        }

        return DB::transaction(function () use ($user, $delta, $reason, $actor, $reference): CreditTransaction {
            $wallet = $this->lock($user);

            $existing = $this->findByReference($reference);

            if ($existing !== null) {
                return $existing;
            }

            $amount = abs($delta);

            if ($wallet->available() < $amount) {
                throw InsufficientCredits::forAdjustment($amount, $wallet->available());
            }

            $this->drawFromLots($wallet, $amount);

            $wallet->balance -= $amount;
            $wallet->last_movement_at = now();
            $wallet->save();

            return $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Adjustment,
                amount: $delta,
                description: 'Manuel düzeltme',
                reference: $reference,
                actor: $actor,
                reason: $reason,
            );
        });
    }

    /**
     * Expires one lot that has reached its date.
     *
     * One lot at a time, each in its own transaction, so a sweep over a hundred thousand
     * wallets does not hold a single lock for minutes. Only the *unheld* remainder can
     * expire: credits promised to a render that is currently running must not vanish
     * underneath it, and the hold will settle within hours anyway.
     */
    public function expireLot(CreditLot $lot): ?CreditTransaction
    {
        return DB::transaction(function () use ($lot): ?CreditTransaction {
            $locked = CreditLot::query()->lockForUpdate()->find($lot->getKey());

            if ($locked === null || $locked->remaining <= 0 || ! $locked->hasExpired()) {
                return null;
            }

            $wallet = $this->lockWallet($locked->wallet_id);

            $expiring = min($locked->remaining, $wallet->available());

            if ($expiring <= 0) {
                return null;
            }

            $locked->remaining -= $expiring;

            if ($locked->remaining === 0) {
                $locked->exhausted_at = now();
            }

            $locked->save();

            $wallet->balance -= $expiring;
            $wallet->lifetime_expired += $expiring;
            $wallet->last_movement_at = now();
            $wallet->save();

            return $this->record(
                wallet: $wallet,
                type: CreditTransactionType::Expire,
                amount: -$expiring,
                description: 'Kredi süresi doldu',
                lotId: $locked->getKey(),
            );
        });
    }

    /**
     * What the ledger says the balance should be.
     *
     * The reconciliation check. Sums the lots rather than the transactions because the
     * lots are what the balance is *made of* — and a mismatch between the two means
     * consumption drew from somewhere it should not have.
     */
    public function reconcile(CreditWallet $wallet): int
    {
        return (int) CreditLot::query()
            ->where('wallet_id', $wallet->getKey())
            ->sum('remaining');
    }

    // --- internals -----------------------------------------------------------

    /**
     * Locks the wallet and returns it as it is *now*.
     *
     * The re-read is the point. A caller who passes in a wallet model read a moment ago
     * is handing over a description of a balance that may already have changed, and
     * every decision in this class is made from what the lock returned instead.
     */
    private function lock(User $user): CreditWallet
    {
        $wallet = $this->walletFor($user);

        return $this->lockWallet((string) $wallet->getKey());
    }

    private function lockWallet(string $walletId): CreditWallet
    {
        /** @var CreditWallet $locked */
        $locked = CreditWallet::query()->lockForUpdate()->findOrFail($walletId);

        return $locked;
    }

    /**
     * Takes credits out of lots, soonest deadline first.
     *
     * Called with the wallet already locked. Lots are locked in the same order every
     * time — the scope's ordering is deterministic — so two consumptions on different
     * wallets can never take each other's lots in opposite orders and deadlock.
     *
     * The caller has already checked the balance; running out here means the lots and
     * the wallet disagree, which is corruption rather than a spending decision, and it
     * says so.
     */
    private function drawFromLots(CreditWallet $wallet, int $credits): void
    {
        $remaining = $credits;

        /** @var array<int, CreditLot> $lots */
        $lots = CreditLot::query()
            ->where('wallet_id', $wallet->getKey())
            ->spendable()
            ->lockForUpdate()
            ->get()
            ->all();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($lot->remaining, $remaining);

            $lot->remaining -= $taken;

            if ($lot->remaining === 0) {
                $lot->exhausted_at = now();
            }

            $lot->save();

            $remaining -= $taken;
        }

        if ($remaining > 0) {
            throw new RuntimeException(sprintf(
                'Cüzdan bakiyesi ile kredi partileri uyuşmuyor: %d kredi karşılıksız.',
                $remaining,
            ));
        }
    }

    private function bumpLifetime(CreditWallet $wallet, CreditLotSource $source, int $credits): void
    {
        match ($source) {
            CreditLotSource::Purchase => $wallet->lifetime_purchased += $credits,
            // A promotion, a refund and a correction are all "given" from the customer's
            // point of view: none of them is money they handed over.
            default => $wallet->lifetime_granted += $credits,
        };
    }

    private function findByReference(?string $reference): ?CreditTransaction
    {
        if ($reference === null) {
            return null;
        }

        return CreditTransaction::query()->where('reference', $reference)->first();
    }

    private function record(
        CreditWallet $wallet,
        CreditTransactionType $type,
        int $amount,
        string $description,
        ?string $lotId = null,
        ?string $reservationId = null,
        ?string $reference = null,
        ?Model $subject = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?User $actor = null,
        ?string $reason = null,
    ): CreditTransaction {
        return CreditTransaction::query()->create([
            'wallet_id' => $wallet->getKey(),
            'type' => $type,
            'amount' => $amount,
            // The balance as it stands after this entry, taken from the locked row.
            'balance_after' => $wallet->balance,
            'reserved_after' => $wallet->reserved,
            'lot_id' => $lotId,
            'reservation_id' => $reservationId,
            'description' => $description,
            'subject_type' => $subject !== null ? $subject::class : $subjectType,
            'subject_id' => $subject?->getKey() ?? $subjectId,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'reference' => $reference,
            'created_at' => now(),
        ]);
    }
}

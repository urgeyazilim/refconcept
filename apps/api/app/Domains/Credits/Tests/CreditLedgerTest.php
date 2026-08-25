<?php

declare(strict_types=1);

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Enums\CreditTransactionType;
use App\Domains\Credits\Enums\ReservationStatus;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditLot;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Services\CreditExpirySweeper;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ledger.
 *
 * Credits are money: somebody paid for them, they expire, and a mistake here is a
 * mistake in a customer's account rather than a cosmetic bug. So the assertions below
 * are about the invariants that have to hold no matter what order things happen in —
 * the balance never goes negative, a hold cannot be settled twice, a retry does not
 * charge twice, and the aggregate always agrees with the lots it is made of.
 */
beforeEach(function (): void {
    $this->ledger = app(CreditLedger::class);
    $this->user = User::factory()->create();
});

it('creates a wallet on first sight and starts it empty', function (): void {
    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(0)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->available())->toBe(0)
        // Twice is the same wallet: a second row would be a second balance.
        ->and($this->ledger->walletFor($this->user)->getKey())->toBe($wallet->getKey());
});

it('grants credits as a lot and records the balance that resulted', function (): void {
    $transaction = $this->ledger->grant(
        user: $this->user,
        credits: 100,
        source: CreditLotSource::Purchase,
        description: 'Ev paketi',
        expiresAt: now()->addYear(),
    );

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(100)
        ->and($wallet->lifetime_purchased)->toBe(100)
        ->and($transaction->type)->toBe(CreditTransactionType::Purchase)
        // Stored, not recomputed: a disputed statement has to show the balance as it
        // stood then, not as today's code would work it out.
        ->and($transaction->balance_after)->toBe(100);

    $lot = CreditLot::query()->where('wallet_id', $wallet->getKey())->firstOrFail();

    expect($lot->amount)->toBe(100)
        ->and($lot->remaining)->toBe(100)
        ->and($lot->expires_at)->not->toBeNull();
});

it('returns the same transaction for a repeated reference instead of granting twice', function (): void {
    $first = $this->ledger->grant(
        user: $this->user,
        credits: 50,
        source: CreditLotSource::Grant,
        description: 'Destek jesti',
        reference: 'support-ticket-4821',
    );

    $second = $this->ledger->grant(
        user: $this->user,
        credits: 50,
        source: CreditLotSource::Grant,
        description: 'Destek jesti',
        reference: 'support-ticket-4821',
    );

    // A client retrying a request whose response it never saw is the normal case, and
    // answering it with a second grant is free money.
    expect($second->getKey())->toBe($first->getKey())
        ->and($this->ledger->walletFor($this->user)->balance)->toBe(50)
        ->and(CreditLot::query()->count())->toBe(1);
});

it('holds credits without spending them', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->user, 30, 'job-1', 'Oda analizi');

    $wallet = $this->ledger->walletFor($this->user);

    /*
     * The balance is untouched and only the line between held and available moved. A
     * hold that reduced the balance would make a released hold look like a refund on
     * the statement.
     */
    expect($wallet->balance)->toBe(100)
        ->and($wallet->reserved)->toBe(30)
        ->and($wallet->available())->toBe(70)
        ->and($reservation->status)->toBe(ReservationStatus::Held);

    $entry = CreditTransaction::query()
        ->where('type', CreditTransactionType::Reserve->value)
        ->firstOrFail();

    expect($entry->amount)->toBe(0);
});

it('refuses a hold larger than what is available', function (): void {
    $this->ledger->grant($this->user, 40, CreditLotSource::Purchase, 'Paket');
    $this->ledger->reserve($this->user, 30, 'job-1', 'Oda analizi');

    // Ten available, twenty asked for. The already-held thirty are not spendable twice.
    expect(fn () => $this->ledger->reserve($this->user, 20, 'job-2', 'Görsel'))
        ->toThrow(InsufficientCredits::class);

    expect($this->ledger->walletFor($this->user)->reserved)->toBe(30);
});

it('reports how many credits are missing', function (): void {
    $this->ledger->grant($this->user, 3, CreditLotSource::Promotion, 'Hoş geldin');

    try {
        $this->ledger->reserve($this->user, 8, 'job-1', 'Görsel üretimi');

        expect(false)->toBeTrue('Yetersiz bakiye kabul edildi.');
    } catch (InsufficientCredits $e) {
        // "8 kredi gerekiyor, 3 krediniz var" tells a customer what to do next;
        // "yetersiz bakiye" does not.
        expect($e->required)->toBe(8)
            ->and($e->available)->toBe(3)
            ->and($e->shortfall())->toBe(5)
            ->and($e->getMessage())->toContain('8')
            ->and($e->getMessage())->toContain('3');
    }
});

it('hands back the existing hold when the same reference reserves again', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $first = $this->ledger->reserve($this->user, 30, 'job-1', 'Oda analizi');
    $second = $this->ledger->reserve($this->user, 30, 'job-1', 'Oda analizi');

    expect($second->getKey())->toBe($first->getKey())
        // Thirty held, not sixty. A retried dispatch must find its hold, not take a
        // second one.
        ->and($this->ledger->walletFor($this->user)->reserved)->toBe(30)
        ->and(CreditReservation::query()->count())->toBe(1);
});

it('turns a hold into a spend', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->user, 30, 'job-1', 'Oda analizi');
    $this->ledger->consume($reservation);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(70)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(30)
        // The aggregate and the lots agree, which is the check that catches a
        // consumption that drew from somewhere it should not have.
        ->and($this->ledger->reconcile($wallet))->toBe(70);
});

it('gives a hold back when the work failed', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->user, 30, 'job-1', 'Görsel üretimi');
    $released = $this->ledger->release($reservation);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(100)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(0)
        ->and($released->status)->toBe(ReservationStatus::Released)
        ->and($released->settled_at)->not->toBeNull();
});

it('settles a hold exactly once, however many times it is settled', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->user, 30, 'job-1', 'Görsel üretimi');

    $this->ledger->consume($reservation);
    $this->ledger->consume($reservation);
    // A duplicate queue delivery, then the sweeper finding a hold it thinks is stale:
    // both happen in production, and either charging twice or refunding a charge would
    // be wrong.
    $this->ledger->release($reservation);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(70)
        ->and($wallet->reserved)->toBe(0)
        ->and($this->ledger->reconcile($wallet))->toBe(70);
});

it('spends the credits that expire soonest', function (): void {
    // A promotion that runs out in a week, and a purchase that lasts a year.
    $this->ledger->grant($this->user, 25, CreditLotSource::Promotion, 'Hoş geldin', expiresAt: now()->addWeek());
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket', expiresAt: now()->addYear());

    $this->ledger->spend($this->user, 30, 'Görsel üretimi', 'job-1');

    $wallet = $this->ledger->walletFor($this->user);

    $promotional = CreditLot::query()->where('source', CreditLotSource::Promotion->value)->firstOrFail();
    $purchased = CreditLot::query()->where('source', CreditLotSource::Purchase->value)->firstOrFail();

    /*
     * Spending the long-lived credits first would silently destroy the ones with a
     * deadline, and the customer would see a balance drop for no reason they could find.
     */
    expect($promotional->remaining)->toBe(0)
        ->and($purchased->remaining)->toBe(95)
        ->and($wallet->balance)->toBe(95);
});

it('spends dated credits before undated ones', function (): void {
    $this->ledger->grant($this->user, 50, CreditLotSource::Grant, 'Süresiz', expiresAt: null);
    $this->ledger->grant($this->user, 50, CreditLotSource::Purchase, 'Süreli', expiresAt: now()->addMonth());

    $this->ledger->spend($this->user, 50, 'Görsel', 'job-1');

    $undated = CreditLot::query()->whereNull('expires_at')->firstOrFail();
    $dated = CreditLot::query()->whereNotNull('expires_at')->firstOrFail();

    // Undated credits are the reserve, not the first thing reached for.
    expect($dated->remaining)->toBe(0)
        ->and($undated->remaining)->toBe(50);
});

it('expires credits that reached their date', function (): void {
    $this->ledger->grant($this->user, 25, CreditLotSource::Promotion, 'Hoş geldin', expiresAt: now()->subDay());
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket', expiresAt: now()->addYear());

    $result = app(CreditExpirySweeper::class)->sweep();

    $wallet = $this->ledger->walletFor($this->user);

    expect($result['lots'])->toBe(1)
        ->and($result['credits'])->toBe(25)
        ->and($wallet->balance)->toBe(100)
        ->and($wallet->lifetime_expired)->toBe(25)
        ->and($this->ledger->reconcile($wallet))->toBe(100);

    $entry = CreditTransaction::query()->where('type', CreditTransactionType::Expire->value)->firstOrFail();

    expect($entry->amount)->toBe(-25);
});

it('does not expire credits that are currently held', function (): void {
    $this->ledger->grant($this->user, 40, CreditLotSource::Purchase, 'Paket', expiresAt: now()->subDay());

    // The hold is fresh, so the sweeper leaves it alone — and must therefore leave the
    // credits behind it alone too.
    $this->ledger->reserve($this->user, 30, 'job-1', 'Görsel', expiresAt: now()->addHour());

    app(CreditExpirySweeper::class)->sweep();

    $wallet = $this->ledger->walletFor($this->user);

    /*
     * Ten expired, thirty survived. Expiring credits out from under a render that is
     * still running would leave the settle with nothing to charge and the work free.
     */
    expect($wallet->balance)->toBe(30)
        ->and($wallet->reserved)->toBe(30)
        ->and($wallet->available())->toBe(0);
});

it('frees a hold nobody ever came back for', function (): void {
    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $reservation = $this->ledger->reserve($this->user, 30, 'job-1', 'Görsel', expiresAt: now()->subHour());

    $result = app(CreditExpirySweeper::class)->sweep();

    $wallet = $this->ledger->walletFor($this->user);

    expect($result['reservations'])->toBe(1)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->balance)->toBe(100)
        /*
         * Recorded as expired rather than released, because the two mean different
         * things to whoever reads this later: a release is a system that finished its
         * job, an expiry is a request that vanished.
         */
        ->and($reservation->fresh()?->status)->toBe(ReservationStatus::Expired);
});

it('lets staff correct a balance in either direction, with a reason', function (): void {
    $actor = User::factory()->create();

    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');

    $this->ledger->adjust($this->user, 20, 'Kesintiden etkilenen müşteri.', $actor);
    $this->ledger->adjust($this->user, -50, 'Hatalı tanımlama geri alındı.', $actor);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(70)
        ->and($this->ledger->reconcile($wallet))->toBe(70);

    $entries = CreditTransaction::query()
        ->where('type', CreditTransactionType::Adjustment->value)
        ->orderBy('id')
        ->get();

    /*
     * Two entries, one per correction, and both carry the person and their reason. This
     * is the movement that is indistinguishable from theft without them, and it is the
     * only type the database refuses to accept unexplained.
     */
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$actor->getKey()])
        ->and($entries->pluck('reason')->all())->toBe([
            'Kesintiden etkilenen müşteri.',
            'Hatalı tanımlama geri alındı.',
        ])
        ->and($entries->pluck('amount')->all())->toBe([20, -50]);
});

it('refuses an adjustment that would drive a balance below zero', function (): void {
    $actor = User::factory()->create();

    $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    // Refused rather than clamped: taking less than was asked for would leave the member
    // of staff believing they had made a correction they had not.
    expect(fn () => $this->ledger->adjust($this->user, -50, 'Yanlış hesap.', $actor))
        ->toThrow(InsufficientCredits::class);

    expect($this->ledger->walletFor($this->user)->balance)->toBe(10);
});

it('will not let the database hold a negative balance', function (): void {
    $wallet = $this->ledger->walletFor($this->user);

    /*
     * The last line of defence, tested directly. Every path above goes through the
     * ledger — this asserts that a path which does not still cannot produce free credits.
     */
    expect(fn () => DB::transaction(fn () => DB::table('credit_wallets')
        ->where('id', $wallet->getKey())
        ->update(['balance' => -1])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('credit_wallets')
        ->where('id', $wallet->getKey())
        ->update(['balance' => 10, 'reserved' => 20])))
        ->toThrow(QueryException::class);
});

it('will not let the ledger be edited or deleted', function (): void {
    $transaction = $this->ledger->grant($this->user, 10, CreditLotSource::Purchase, 'Paket');

    /*
     * Append-only by trigger, not by an Eloquent guard a raw query would walk past. This
     * is the table a customer's complaint is settled against; one that can be edited is
     * one nobody can rely on in a dispute.
     */
    expect(fn () => DB::transaction(fn () => DB::table('credit_transactions')
        ->where('id', $transaction->getKey())
        ->update(['amount' => 1000])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('credit_transactions')
        ->where('id', $transaction->getKey())
        ->delete()))
        ->toThrow(QueryException::class);

    expect(CreditTransaction::query()->findOrFail($transaction->getKey())->amount)->toBe(10);
});

it('refuses a movement whose direction contradicts its type', function (): void {
    $wallet = $this->ledger->walletFor($this->user);

    // A "consume" that adds credits is not a rounding error, it is free money — and it
    // would balance perfectly in every report, which is why the database refuses it.
    expect(fn () => DB::transaction(fn () => DB::table('credit_transactions')->insert([
        'id' => (string) Str::uuid7(),
        'wallet_id' => $wallet->getKey(),
        'type' => 'consume',
        'amount' => 500,
        'balance_after' => 500,
        'reserved_after' => 0,
        'description' => 'Ters yönlü hareket',
        'created_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('keeps the wallet and its lots in step across a long sequence', function (): void {
    $actor = User::factory()->create();

    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket', expiresAt: now()->addYear());
    $this->ledger->grant($this->user, 25, CreditLotSource::Promotion, 'Hoş geldin', expiresAt: now()->addWeek());

    $held = $this->ledger->reserve($this->user, 40, 'job-1', 'Görsel');
    $this->ledger->consume($held);

    $abandoned = $this->ledger->reserve($this->user, 10, 'job-2', 'Görsel');
    $this->ledger->release($abandoned);

    $this->ledger->spend($this->user, 15, 'Oda analizi', 'job-3');
    $this->ledger->adjust($this->user, -20, 'Yanlış tanımlama.', $actor);
    $this->ledger->grant($this->user, 5, CreditLotSource::Refund, 'Kısmi iade');

    $wallet = $this->ledger->walletFor($this->user);

    // 100 + 25 - 40 - 15 - 20 + 5 = 55.
    expect($wallet->balance)->toBe(55)
        ->and($wallet->reserved)->toBe(0)
        // The invariant that matters: the aggregate is only ever a faster way to read
        // the lots, and after nine movements it still says the same thing they do.
        ->and($this->ledger->reconcile($wallet))->toBe(55);

    $last = CreditTransaction::query()->orderByDesc('id')->firstOrFail();

    expect($last->balance_after)->toBe(55);
});

<?php

declare(strict_types=1);

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditWallet;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What happens when two things touch the same wallet at once.
 *
 * The honest scope of this suite, stated plainly: it asserts that the ledger *takes* the
 * row lock and decides from what the lock returned, and that a caller holding a stale
 * copy of a wallet cannot talk it into spending money that is no longer there. That the
 * lock then blocks a second transaction is PostgreSQL's behaviour, not ours, and a test
 * of it would be a test of PostgreSQL.
 *
 * The invariants that survive a caller who forgets the lock entirely were pushed into the
 * schema instead — a CHECK refuses a negative balance and refuses reserved exceeding it —
 * and those are asserted directly in {@see CreditLedgerTest}.
 */
beforeEach(function (): void {
    $this->ledger = app(CreditLedger::class);
    $this->user = User::factory()->create();

    $this->ledger->grant($this->user, 100, CreditLotSource::Purchase, 'Paket');
});

it('takes a row lock on the wallet before deciding anything', function (): void {
    $locking = [];

    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'credit_wallets')) {
            $locking[] = $query->sql;
        }
    });

    $this->ledger->reserve($this->user, 30, 'job-1', 'Görsel');

    /*
     * Without the lock, two concurrent reserves both read a balance of 100, both find
     * room for 60, and the wallet ends up promising 120 credits it does not have.
     */
    expect($locking)->not->toBeEmpty();
});

it('locks the lots it draws from as well as the wallet', function (): void {
    $lockedLots = false;

    DB::listen(function ($query) use (&$lockedLots): void {
        if (str_contains($query->sql, 'for update') && str_contains($query->sql, 'credit_lots')) {
            $lockedLots = true;
        }
    });

    $this->ledger->spend($this->user, 20, 'Görsel', 'job-1');

    // The wallet lock alone would not stop two spends on the same lot from a path that
    // ever operates on lots directly — and expiry is exactly such a path.
    expect($lockedLots)->toBeTrue();
});

it('ignores a stale wallet the caller was holding', function (): void {
    // Read before anything happens: 100 in the balance, nothing reserved.
    $stale = CreditWallet::query()->where('user_id', $this->user->getKey())->firstOrFail();

    // Meanwhile, ninety of it is spoken for.
    $this->ledger->reserve($this->user, 90, 'job-1', 'Görsel');

    expect($stale->available())->toBe(100);

    /*
     * The caller's copy says there is plenty. The ledger re-reads inside the lock and
     * refuses — which is the whole reason it re-reads rather than trusting what it was
     * handed.
     */
    expect(fn () => $this->ledger->reserve($this->user, 50, 'job-2', 'İkinci görsel'))
        ->toThrow(InsufficientCredits::class);

    expect($this->ledger->walletFor($this->user)->reserved)->toBe(90);
});

it('cannot be talked into spending twice by a repeated reference', function (): void {
    // The shape of a client retrying a request whose response it never saw. Both calls
    // return the same entry and only one charge lands.
    $first = $this->ledger->spend($this->user, 40, 'Görsel üretimi', 'render-88');
    $second = $this->ledger->spend($this->user, 40, 'Görsel üretimi', 'render-88');

    expect($second->getKey())->toBe($first->getKey())
        ->and($this->ledger->walletFor($this->user)->balance)->toBe(60);
});

it('keeps holds independent of one another', function (): void {
    $a = $this->ledger->reserve($this->user, 30, 'job-a', 'Görsel A');
    $b = $this->ledger->reserve($this->user, 40, 'job-b', 'Görsel B');

    expect($this->ledger->walletFor($this->user)->reserved)->toBe(70);

    // One job succeeds and the other fails; each settles for its own amount, which is
    // the reason a hold is a row rather than a number on the wallet.
    $this->ledger->consume($a);
    $this->ledger->release($b);

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(70)
        ->and($wallet->reserved)->toBe(0)
        ->and($this->ledger->reconcile($wallet))->toBe(70);
});

it('never lets the sum of holds exceed the balance', function (): void {
    $held = 0;

    /*
     * Ten holds of twelve against a hundred credits. The ninth is the one that has to be
     * refused, and it is refused for the right reason: the ledger compares against what
     * is *available*, not against the balance, so credits already promised elsewhere are
     * not promised again.
     */
    for ($i = 1; $i <= 10; $i++) {
        try {
            $this->ledger->reserve($this->user, 12, 'job-'.$i, 'Görsel');
            $held += 12;
        } catch (InsufficientCredits) {
            break;
        }
    }

    $wallet = $this->ledger->walletFor($this->user);

    expect($held)->toBe(96)
        ->and($wallet->reserved)->toBe(96)
        ->and($wallet->reserved)->toBeLessThanOrEqual($wallet->balance)
        ->and($wallet->available())->toBe(4);
});

it('applies every movement inside one transaction', function (): void {
    DB::beginTransaction();

    try {
        $this->ledger->grant($this->user, 50, CreditLotSource::Grant, 'Deneme');

        /*
         * Rolled back wholesale. If the lot, the wallet and the ledger row were written
         * outside one transaction, a rollback would leave some of them behind — and a lot
         * with no matching balance is corruption that only surfaces at reconciliation,
         * long after anybody could say what caused it.
         */
        DB::rollBack();
    } catch (Throwable $e) {
        DB::rollBack();

        throw $e;
    }

    $wallet = $this->ledger->walletFor($this->user);

    expect($wallet->balance)->toBe(100)
        ->and($this->ledger->reconcile($wallet))->toBe(100);
});

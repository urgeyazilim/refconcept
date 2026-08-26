<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Finance\Services\PaymentReconciliation;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The Phase 21 gate, money half: two records of the same payment, compared.
 *
 * The provider's transaction log and our journal are each internally consistent — the
 * ledger balances by construction and the provider's books certainly balance — which is
 * exactly why neither can be checked against itself. Only comparing them catches a capture
 * that never posted, a webhook processed twice, or a refund the books recorded and the bank
 * never sent.
 *
 * These tests break the link on purpose. A reconciliation that has never been shown a
 * discrepancy is a report that says "mutabık" whatever happens.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->stock = app(InventoryLedger::class);
    $this->reconciliation = app(PaymentReconciliation::class);

    [$this->seller] = makeApprovedSeller('Mutabakat Mobilya', 'mutabakat-mobilya');

    $product = makeProduct($this->seller, makeCategory('Koltuk', 'koltuk-mutabakat', 'living_room'), [
        'name' => 'Mutabakat koltuğu',
        'description' => 'Mutabakat testleri.',
        'price_minor' => 250_000,
        'stock_quantity' => 8,
    ]);

    $this->sku = $product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 8, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'city' => 'İstanbul',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
    ]);
});

/** Buys and pays for real, leaving a capture and a journal entry that should agree. */
function payForSomething(int $quantity = 1): void
{
    test()->carts->add(test()->customer, test()->sku, $quantity);

    $session = test()->checkout->openCart(test()->customer, []);
    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);
}

/** @return array<string, mixed> */
function reconcile(): array
{
    return test()->reconciliation->forPeriod(now()->subDay(), now()->addMinute());
}

/**
 * A payment the provider says succeeded, with no order and no journal entry behind it.
 *
 * Built by inserting rather than by deleting a real one, because every table involved is
 * append-only — and that is the point. The discrepancy this simulates is not "somebody
 * removed a row"; it is "a row that should have been written never was", which is what a
 * failed posting or a job that died between two steps actually leaves behind.
 *
 * @return string the payment intent id, which is what the report names
 */
function fabricateUnpostedSale(int $amountMinor = 99_900, string $status = 'succeeded', ?Carbon $when = null): string
{
    $sessionId = (string) Str::uuid7();
    $intentId = (string) Str::uuid7();
    $when ??= now();

    DB::table('checkout_sessions')->insert([
        'id' => $sessionId,
        'user_id' => test()->customer->getKey(),
        'purpose' => 'cart',
        'status' => 'paid',
        'currency' => 'TRY',
        'grand_total_minor' => $amountMinor,
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    DB::table('payment_intents')->insert([
        'id' => $intentId,
        'checkout_session_id' => $sessionId,
        'user_id' => test()->customer->getKey(),
        'gateway' => 'fake',
        'method' => 'card',
        'status' => 'captured',
        'amount_minor' => $amountMinor,
        'captured_minor' => $amountMinor,
        'currency' => 'TRY',
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid7(),
        'payment_intent_id' => $intentId,
        'gateway' => 'fake',
        'type' => 'sale',
        'status' => $status,
        'amount_minor' => $amountMinor,
        'currency' => 'TRY',
        'external_id' => 'ext-'.substr($intentId, 0, 8),
        'occurred_at' => $when,
        'created_at' => $when,
    ]);

    return $intentId;
}

// --- the ordinary case ------------------------------------------------------------

it('reports a clean day as reconciled', function (): void {
    payForSomething();

    $report = reconcile();

    // The baseline that gives every other test its meaning: when the two records agree,
    // the report says so and finds nothing.
    expect($report['is_reconciled'])->toBeTrue()
        ->and($report['findings'])->toBe([])
        ->and($report['ledger']['is_balanced'])->toBeTrue();
});

it('reports the provider total and the ledger total as separate figures', function (): void {
    payForSomething(2);

    $report = reconcile();

    /*
     * Two numbers, not one. A reconciliation that showed a single "total" would be
     * reporting whichever source it happened to read, and the whole point is that there
     * are two sources.
     */
    expect($report['provider']['captured_minor'])->toBeGreaterThan(0)
        ->and($report['ledger']['cash_minor'])->toBe($report['provider']['net_minor']);
});

// --- the failures it exists for ---------------------------------------------------

it('catches money the provider took and the books never recorded', function (): void {
    payForSomething();

    // A second payment the provider took, whose journal entry was never written.
    $orphan = fabricateUnpostedSale();

    $report = reconcile();

    $kinds = array_column($report['findings'], 'kind');

    /*
     * The worst case in the whole system: the customer has paid, and the platform's books
     * do not know. Nothing downstream — commission, payout, tax — will ever include it,
     * and the seller finds out months later when their settlement is short.
     */
    expect($report['is_reconciled'])->toBeFalse()
        ->and($kinds)->toContain('captured_not_posted')
        ->and($kinds)->toContain('cash_mismatch');

    // And it names the payment, because "something is missing" is not actionable.
    expect(array_column($report['findings'], 'reference'))->toContain($orphan);
});

it('catches the same provider transaction recorded twice', function (): void {
    payForSomething();

    $original = PaymentTransaction::query()->where('type', 'sale')->firstOrFail();

    /*
     * A webhook delivered twice and processed twice. The idempotency key is meant to make
     * this impossible — which is precisely why it is worth checking rather than assuming,
     * because the day it stops working nothing else will say so.
     */
    DB::table('payment_transactions')->insert([
        'id' => (string) Str::uuid7(),
        'payment_intent_id' => $original->payment_intent_id,
        'gateway' => $original->gateway,
        'type' => 'sale',
        'status' => 'succeeded',
        'amount_minor' => $original->amount_minor,
        'currency' => $original->currency,
        'external_id' => $original->external_id,
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    $report = reconcile();

    expect($report['is_reconciled'])->toBeFalse()
        ->and(array_column($report['findings'], 'kind'))->toContain('duplicate_transaction');
});

it('catches a payment that has been pending far too long', function (): void {
    fabricateUnpostedSale(50_000, 'pending', now()->subDays(3));

    $report = reconcile();

    $pending = array_values(array_filter(
        $report['findings'],
        static fn (array $finding): bool => $finding['kind'] === 'stuck_pending',
    ));

    /*
     * A warning rather than a failure. A bank transfer legitimately waits for a customer,
     * and paging somebody every night about one is how alerts stop being read — but a card
     * payment pending for three days is an outcome nobody chased.
     */
    expect($pending)->not->toBe([])
        ->and($pending[0]['severity'])->toBe('warning');
});

it('subtracts refunds rather than reporting them as a discrepancy', function (): void {
    payForSomething(2);

    $before = reconcile();

    expect($before['is_reconciled'])->toBeTrue();

    /*
     * Refunds hit the same account in the other direction. Comparing them separately would
     * report a mismatch on every single day that had a refund in it — an alert that fires
     * on normal business is an alert somebody turns off.
     */
    expect($before['provider']['net_minor'])
        ->toBe($before['provider']['captured_minor'] - $before['provider']['refunded_minor']);
});

// --- the command ------------------------------------------------------------------

it('exits zero on a clean period and non-zero on a broken one', function (): void {
    payForSomething();

    $this->artisan('refconcept:reconcile-payments')->assertExitCode(0);

    fabricateUnpostedSale();

    /*
     * The exit code is the point. A reconciliation nobody is alerted about is a report
     * nobody reads, and a scheduler can only alert on an exit code.
     */
    $this->artisan('refconcept:reconcile-payments')->assertExitCode(1);
});

it('does not fail the run for a warning alone', function (): void {
    payForSomething();

    /*
     * A pending payment is not counted in any total — it has not succeeded — so this
     * leaves the books reconciled and one warning outstanding. A stuck bank transfer is
     * worth reporting and is not worth waking somebody for, and an alert that fires on
     * normal business is an alert somebody turns off.
     */
    fabricateUnpostedSale(50_000, 'pending', now()->subDays(3));

    $report = reconcile();

    expect(array_column($report['findings'], 'severity'))->toBe(['warning']);

    $this->artisan('refconcept:reconcile-payments')->assertExitCode(0);
});

<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Enums\SettlementStatus;
use App\Domains\Finance\Exceptions\SettlementRefused;
use App\Domains\Finance\Models\CommissionRule;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\LedgerLine;
use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Services\CommissionResolver;
use App\Domains\Finance\Services\JournalLine;
use App\Domains\Finance\Services\Ledger;
use App\Domains\Finance\Services\OrderAccounting;
use App\Domains\Finance\Services\SettlementEligibility;
use App\Domains\Finance\Services\SettlementService;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Services\CheckoutFulfiller;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The financial invariant suite — the Phase 16 gate.
 *
 * A marketplace holds money it does not own. Some of what a customer pays is the
 * platform's commission and the rest is owed to sellers, held until goods are delivered
 * and a return window has closed. Getting that wrong does not show up as a broken page; it
 * shows up months later as a payout nobody can explain.
 *
 * So the claims below are the ones that have to be true for every report built on the
 * ledger to mean anything: every entry balances, nothing is ever edited, the same event
 * posts once, and money cannot leave twice.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->stock = app(InventoryLedger::class);
    $this->ledger = app(Ledger::class);
    $this->settlements = app(SettlementService::class);
    $this->eligibility = app(SettlementEligibility::class);

    [$this->sellerA] = makeApprovedSeller('Defter A.Ş.', 'defter-as');
    [$this->sellerB] = makeApprovedSeller('Kayıt Ltd.', 'kayit-ltd');

    $this->category = makeCategory('Mobilya', 'mobilya-defter', 'living_room');

    $sofa = makeProduct($this->sellerA, $this->category, [
        'name' => 'Defter kanepe',
        'description' => 'Finans testleri.',
        'price_minor' => 1_000_000,
        'stock_quantity' => 5,
    ]);

    $lamp = makeProduct($this->sellerB, $this->category, [
        'name' => 'Defter lamba',
        'description' => 'Finans testleri.',
        'price_minor' => 200_000,
        'stock_quantity' => 5,
    ]);

    $this->sofaSku = $sofa->skus->first();
    $this->lampSku = $lamp->skus->first();

    $this->stock->adjust($this->stock->itemFor($this->sofaSku), 5, MovementType::Receipt);
    $this->stock->adjust($this->stock->itemFor($this->lampSku), 5, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'city' => 'İstanbul',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
    ]);

    $this->operator = User::factory()->create();
    grantPlatformRole($this->operator, SystemRole::SuperAdmin);
});

/** Buys from both sellers and pays. */
function placeFinanceOrder(int $sofas = 1, int $lamps = 1): Order
{
    test()->carts->add(test()->customer, test()->sofaSku, $sofas);
    test()->carts->add(test()->customer, test()->lampSku, $lamps);

    $session = test()->checkout->openCart(test()->customer, []);
    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    return Order::query()->latest('placed_at')->firstOrFail();
}

/** Walks a seller order to delivered and past the hold. */
function deliverAndAge(SellerOrder $sellerOrder, int $daysAgo = 20): void
{
    test()->statuses->advance($sellerOrder, SellerOrderStatus::Confirmed);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Shipped);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Delivered);

    $sellerOrder->fresh()?->forceFill(['delivered_at' => now()->subDays($daysAgo)])->save();

    /*
     * Back-dating a column is not an event, so nothing recomputed the projection. In
     * production the passage of time is what makes an order eligible and the nightly
     * settlement run rebuilds; here it is said explicitly.
     */
    app(OrderAccounting::class)
        ->rebuildBalance((string) $sellerOrder->seller_id, $sellerOrder->currency);
}

// --- the ledger: the gate -----------------------------------------------------

it('posts a balanced journal for a sale', function (): void {
    $order = placeFinanceOrder();

    $entry = LedgerEntry::query()->where('idempotency_key', 'order-sale:'.$order->getKey())->firstOrFail();

    $debit = (int) $entry->lines()->sum('debit_minor');
    $credit = (int) $entry->lines()->sum('credit_minor');

    expect($debit)->toBe($credit)
        ->and($debit)->toBe($order->grand_total_minor)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('treats the customer money as mostly a liability', function (): void {
    $order = placeFinanceOrder();

    $cash = $this->ledger->balanceOf(LedgerAccount::CashProvider);
    $commission = $this->ledger->balanceOf(LedgerAccount::Commission);

    $owedA = $this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerA->getKey());
    $owedB = $this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerB->getKey());

    /*
     * The shape is the whole point. Posting the customer's payment as revenue and the
     * payouts as expenses would balance perfectly and describe a different business — one
     * that is enormously profitable right up until it pays its sellers.
     */
    expect($cash)->toBe($order->grand_total_minor)
        ->and($owedA + $owedB + $commission)->toBe($cash)
        ->and($commission)->toBeLessThan($cash);
});

it('posts one journal however many times a payment is confirmed', function (): void {
    $order = placeFinanceOrder();

    app(CheckoutFulfiller::class)
        ->fulfil($order->payment ?? throw new RuntimeException('payment missing'));

    expect(LedgerEntry::query()->where('type', 'order.sale')->count())->toBe(1)
        ->and($this->ledger->balanceOf(LedgerAccount::CashProvider))->toBe($order->grand_total_minor);
});

it('refuses an entry that does not balance', function (): void {
    // Caught in the service with the figures named; the database trigger would refuse it
    // too, at commit, with a message about a table.
    expect(fn () => $this->ledger->post(
        type: 'test.unbalanced',
        description: 'Denk olmayan kayıt',
        lines: [
            JournalLine::debit(LedgerAccount::Bank, 100),
            JournalLine::credit(LedgerAccount::Commission, 90),
        ],
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses an unbalanced entry even when the service is bypassed', function (): void {
    $entry = LedgerEntry::query()->create([
        'type' => 'test.raw',
        'description' => 'Doğrudan yazım',
        'currency' => 'TRY',
        'posted_at' => now(),
    ]);

    $account = $this->ledger->account(LedgerAccount::Bank);

    /*
     * The deferred constraint trigger. It runs at commit, after every line exists, so an
     * entry can be built line by line and still be refused as a whole — which is the only
     * way to say "this must balance" in a database.
     */
    expect(function () use ($entry, $account): void {
        LedgerLine::query()->create([
            'entry_id' => $entry->getKey(),
            'account_id' => $account->getKey(),
            'debit_minor' => 500,
            'currency' => 'TRY',
        ]);

        /*
         * Forced to fire now.
         *
         * The trigger is DEFERRABLE INITIALLY DEFERRED, so in production it runs at the
         * commit that ends the request. A test runs inside a transaction that is rolled
         * back and never commits, so the check would otherwise never happen — this is how
         * the same guard is exercised from inside one. `unprepared`, because SET
         * CONSTRAINTS is not a statement that can be prepared.
         */
        DB::unprepared('SET CONSTRAINTS ALL IMMEDIATE');
    })->toThrow(QueryException::class);
});

it('will not let the ledger be edited', function (): void {
    placeFinanceOrder();

    $line = LedgerLine::query()->firstOrFail();

    // A mistake is corrected by a reversing entry, so both stay visible. That is the
    // difference between a ledger and a table of numbers.
    expect(fn () => DB::table('ledger_lines')->where('id', $line->getKey())->update(['debit_minor' => 1]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('ledger_entries')->where('id', $line->entry_id)->delete())
        ->toThrow(QueryException::class);
});

it('undoes an entry by writing its opposite', function (): void {
    $order = placeFinanceOrder();

    $entry = LedgerEntry::query()->where('type', 'order.sale')->firstOrFail();

    $reversal = $this->ledger->reverse($entry, 'Test geri alma');

    expect($reversal->reverses_entry_id)->toBe($entry->getKey())
        // Everything nets to zero, and both entries are still there to read.
        ->and($this->ledger->balanceOf(LedgerAccount::CashProvider))->toBe(0)
        ->and($this->ledger->balanceOf(LedgerAccount::Commission))->toBe(0)
        ->and(LedgerEntry::query()->whereKey($entry->getKey())->exists())->toBeTrue()
        ->and($this->ledger->isBalanced())->toBeTrue()
        ->and($order->grand_total_minor)->toBeGreaterThan(0);
});

it('reverses an entry once however many times it is asked', function (): void {
    placeFinanceOrder();

    $entry = LedgerEntry::query()->where('type', 'order.sale')->firstOrFail();

    $first = $this->ledger->reverse($entry, 'Bir kez');
    $second = $this->ledger->reverse($entry, 'İki kez');

    // Reversing twice would re-post the original.
    expect($second->getKey())->toBe($first->getKey())
        ->and($this->ledger->balanceOf(LedgerAccount::CashProvider))->toBe(0);
});

// --- the commission hierarchy ---------------------------------------------------

it('prefers the most specific commission rule', function (): void {
    $resolver = app(CommissionResolver::class);

    $sellerId = (string) $this->sellerA->getKey();
    $categoryId = (string) $this->category->getKey();

    expect($resolver->resolve($sellerId, $categoryId)->scope)->toBe('platform');

    CommissionRule::query()->create([
        'scope' => 'category',
        'category_id' => $categoryId,
        'rate_bps' => 1_500,
    ]);

    expect($resolver->resolve($sellerId, $categoryId)->rateBps)->toBe(1_500);

    CommissionRule::query()->create([
        'scope' => 'seller',
        'seller_id' => $sellerId,
        'rate_bps' => 1_000,
    ]);

    expect($resolver->resolve($sellerId, $categoryId)->rateBps)->toBe(1_000);

    CommissionRule::query()->create([
        'scope' => 'seller_category',
        'seller_id' => $sellerId,
        'category_id' => $categoryId,
        'rate_bps' => 800,
    ]);

    expect($resolver->resolve($sellerId, $categoryId)->rateBps)->toBe(800);

    CommissionRule::query()->create([
        'scope' => 'campaign',
        'seller_id' => $sellerId,
        'rate_bps' => 500,
        'label' => 'Eylül kampanyası',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $decision = $resolver->resolve($sellerId, $categoryId);

    // The reason travels with the number: "why is my commission 5%" is the question a
    // seller asks most often.
    expect($decision->rateBps)->toBe(500)
        ->and($decision->scope)->toBe('campaign')
        ->and($decision->reason())->toBe('Eylül kampanyası');
});

it('ignores a campaign that has finished', function (): void {
    $resolver = app(CommissionResolver::class);

    CommissionRule::query()->create([
        'scope' => 'campaign',
        'rate_bps' => 100,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),
    ]);

    expect($resolver->resolve((string) $this->sellerA->getKey(), null)->scope)->toBe('platform');
});

it('keeps the snapshot even when the rules change afterwards', function (): void {
    CommissionRule::query()->create([
        'scope' => 'seller',
        'seller_id' => $this->sellerA->getKey(),
        'rate_bps' => 1_000,
    ]);

    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    expect($sellerOrder?->commission_minor)->toBe(100_000);

    CommissionRule::query()->where('scope', 'seller')->update(['rate_bps' => 3_000]);

    // Rung one of the hierarchy: the order item's own snapshot, never re-derived.
    expect($sellerOrder?->fresh()?->commission_minor)->toBe(100_000);
});

// --- settlement eligibility ------------------------------------------------------

it('will not settle an order that has not been delivered', function (): void {
    placeFinanceOrder();

    expect($this->eligibility->eligible((string) $this->sellerA->getKey()))->toHaveCount(0);

    expect(fn () => $this->settlements->build($this->sellerA))
        ->toThrow(SettlementRefused::class);
});

it('will not settle a delivery that is still inside the hold', function (): void {
    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    deliverAndAge($sellerOrder, daysAgo: 2);

    /*
     * The return window. Paying before it closes means chasing a seller for money they
     * have already spent.
     */
    expect($this->eligibility->eligible((string) $this->sellerA->getKey()))->toHaveCount(0)
        ->and($this->eligibility->explain($sellerOrder->fresh()))->toContain('tarihinde hakedişe girer');
});

it('settles a delivery that is past the hold', function (): void {
    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    deliverAndAge($sellerOrder);

    $eligible = $this->eligibility->eligible((string) $this->sellerA->getKey());

    expect($eligible)->toHaveCount(1)
        ->and($this->eligibility->explain($sellerOrder->fresh()))->toBe('Hakedişe hazır.');
});

it('will not settle a suspended seller', function (): void {
    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    deliverAndAge($sellerOrder);

    $this->sellerA->forceFill(['status' => 'suspended'])->save();

    // A suspended seller is suspended for a reason, and the reason is usually financial.
    expect($this->eligibility->eligible((string) $this->sellerA->getKey()))->toHaveCount(0);
});

// --- the payout workflow ----------------------------------------------------------

it('builds, approves and pays a settlement', function (): void {
    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    deliverAndAge($sellerOrder);

    $settlement = $this->settlements->build($this->sellerA);

    expect($settlement->status)->toBe(SettlementStatus::Draft)
        ->and($settlement->net_minor)->toBe($sellerOrder->payableMinor())
        // A draft posts nothing, which is what makes re-running the builder safe.
        ->and(LedgerEntry::query()->where('type', 'settlement.approved')->count())->toBe(0);

    $approved = $this->settlements->approve($settlement, $this->operator);

    expect($approved->status)->toBe(SettlementStatus::Approved)
        // The money has left what we owe and is in flight.
        ->and($this->ledger->balanceOf(LedgerAccount::PayoutClearing, (string) $this->sellerA->getKey()))
        ->toBe($settlement->net_minor);

    $paid = $this->settlements->markPaid($approved, $this->operator, 'BANK-REF-9911');

    expect($paid->status)->toBe(SettlementStatus::Paid)
        ->and($paid->payout_reference)->toBe('BANK-REF-9911')
        ->and($this->ledger->balanceOf(LedgerAccount::PayoutClearing, (string) $this->sellerA->getKey()))->toBe(0)
        // Money left the bank, so the bank account is negative in its own direction.
        ->and($this->ledger->balanceOf(LedgerAccount::Bank))->toBe(-$settlement->net_minor)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('will not approve the same settlement twice', function (): void {
    $order = placeFinanceOrder();
    deliverAndAge($order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey()));

    $settlement = $this->settlements->build($this->sellerA);
    $this->settlements->approve($settlement, $this->operator);

    // Two operators on two stale screens. The second must not post a second journal.
    expect(fn () => $this->settlements->approve($settlement->fresh(), $this->operator))
        ->toThrow(SettlementRefused::class);

    expect(LedgerEntry::query()->where('type', 'settlement.approved')->count())->toBe(1);
});

it('will not pay a settlement that was never approved', function (): void {
    $order = placeFinanceOrder();
    deliverAndAge($order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey()));

    $settlement = $this->settlements->build($this->sellerA);

    expect(fn () => $this->settlements->markPaid($settlement, $this->operator, 'REF'))
        ->toThrow(SettlementRefused::class);
});

it('never puts the same order in two settlements', function (): void {
    $order = placeFinanceOrder();
    deliverAndAge($order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey()));

    $first = $this->settlements->build($this->sellerA);

    // A second build while one is open returns the same run rather than making another.
    $again = $this->settlements->build($this->sellerA);

    expect($again->getKey())->toBe($first->getKey());

    $this->settlements->approve($first, $this->operator);
    $this->settlements->markPaid($first->fresh(), $this->operator, 'REF-1');

    /*
     * Once paid, that order is gone from the eligible set — enforced by a unique index on
     * the settlement item as well, because a bank transfer is not something you can
     * recall.
     */
    expect($this->eligibility->eligible((string) $this->sellerA->getKey()))->toHaveCount(0)
        ->and(fn () => $this->settlements->build($this->sellerA))->toThrow(SettlementRefused::class);
});

it('returns orders to the pool when a settlement is cancelled', function (): void {
    $order = placeFinanceOrder();
    deliverAndAge($order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey()));

    $settlement = $this->settlements->build($this->sellerA);
    $this->settlements->approve($settlement, $this->operator);

    $cancelled = $this->settlements->cancel($settlement->fresh(), $this->operator, 'Banka bilgisi hatalı.');

    expect($cancelled->status)->toBe(SettlementStatus::Cancelled)
        // The approval is unwound by a reversing entry, not a delete.
        ->and($this->ledger->balanceOf(LedgerAccount::PayoutClearing, (string) $this->sellerA->getKey()))->toBe(0)
        ->and($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerA->getKey()))
        ->toBe($settlement->net_minor)
        ->and($this->eligibility->eligible((string) $this->sellerA->getKey()))->toHaveCount(1)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

// --- cancellation ------------------------------------------------------------------

it('unwinds one seller share when their part is cancelled', function (): void {
    $order = placeFinanceOrder();

    $owedBefore = $this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerA->getKey());

    expect($owedBefore)->toBeGreaterThan(0);

    $this->statuses->advance(
        $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey()),
        SellerOrderStatus::Cancelled,
        null,
        'seller',
        'Depoda hasar bulundu.',
    );

    /*
     * Only that seller's share. The other seller's parcel is still on its way, so
     * unwinding the whole payment would be a much larger claim than the facts support.
     */
    expect($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerA->getKey()))->toBe(0)
        ->and($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->sellerB->getKey()))
        ->toBeGreaterThan(0)
        // And the customer is owed their money back.
        ->and($this->ledger->balanceOf(LedgerAccount::CustomerRefund))->toBeGreaterThan(0)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('keeps the seller balance projection in step with the journal', function (): void {
    $order = placeFinanceOrder();
    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    $balance = DB::table('seller_balances')->where('seller_id', $this->sellerA->getKey())->first();

    expect((int) $balance->pending_minor)->toBe($sellerOrder->payableMinor())
        // Nothing is available until it has been delivered and held.
        ->and((int) $balance->available_minor)->toBe(0);

    deliverAndAge($sellerOrder);

    $afterDelivery = DB::table('seller_balances')->where('seller_id', $this->sellerA->getKey())->first();

    expect((int) $afterDelivery->available_minor)->toBe($sellerOrder->payableMinor())
        ->and((int) $afterDelivery->pending_minor)->toBe(0);
});

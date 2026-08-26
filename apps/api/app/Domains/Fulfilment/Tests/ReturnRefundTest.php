<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Services\Ledger;
use App\Domains\Finance\Services\OrderAccounting;
use App\Domains\Finance\Services\SettlementEligibility;
use App\Domains\Fulfilment\Enums\RefundStatus;
use App\Domains\Fulfilment\Enums\ReturnStatus;
use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\Refund;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Fulfilment\Services\RefundService;
use App\Domains\Fulfilment\Services\ReturnService;
use App\Domains\Fulfilment\Services\ShipmentService;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The Phase 17 gate: partial returns, partial refunds, and a provider that says no.
 *
 * Two claims run through all of it.
 *
 * **Goods and money travel separately.** A return can be approved and the refund fail at
 * the provider; a refund can be issued with nothing coming back. Folding them into one
 * field makes both impossible to represent and therefore impossible to fix — so a failed
 * refund is a state that can be retried, not an exception that escaped.
 *
 * **Everything is per line and per quantity.** A customer who bought four chairs and wants
 * to return one is the ordinary case, and an order-level model turns it into a support
 * conversation.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->shipments = app(ShipmentService::class);
    $this->returns = app(ReturnService::class);
    $this->refunds = app(RefundService::class);
    $this->ledger = app(Ledger::class);
    $this->eligibility = app(SettlementEligibility::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller, $this->sellerOwner] = makeApprovedSeller('İade A.Ş.', 'iade-as');

    // A flat 10% so the arithmetic in the assertions is readable.
    $this->seller->forceFill(['default_commission_bps' => 1_000])->save();

    $product = makeProduct($this->seller, makeCategory('Sandalye', 'sandalye-iade', 'living_room'), [
        'name' => 'İade sandalyesi',
        'description' => 'İade testleri.',
        'price_minor' => 100_000,
        'stock_quantity' => 10,
    ]);

    $this->sku = $product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 10, MovementType::Receipt);

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

/** Buys `$quantity` chairs, pays, and delivers them. */
function buyAndDeliver(int $quantity = 4): Order
{
    test()->carts->add(test()->customer, test()->sku, $quantity);

    $session = test()->checkout->openCart(test()->customer, []);
    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();
    $sellerOrder = $order->sellerOrders->first();

    test()->statuses->advance($sellerOrder, SellerOrderStatus::Confirmed);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Shipped);
    test()->statuses->advance($sellerOrder->fresh(), SellerOrderStatus::Delivered);

    return $order->fresh(['sellerOrders.items', 'items']) ?? $order;
}

// --- shipping ------------------------------------------------------------------

it('ships part of an order without calling the whole thing shipped', function (): void {
    test()->carts->add($this->customer, $this->sku, 4);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();
    $sellerOrder = $order->sellerOrders->first();
    $item = $sellerOrder->items->first();

    $this->statuses->advance($sellerOrder, SellerOrderStatus::Confirmed);

    $this->shipments->ship($sellerOrder->fresh(), [
        ['order_item_id' => (string) $item->getKey(), 'quantity' => 3],
    ], 'Test Kargo', 'TK-1');

    /*
     * Three of four gone. A seller who sees "kargoya verildi" here has been given a status
     * that will confuse their customer for a week.
     */
    expect($sellerOrder->fresh()?->status)->toBe(SellerOrderStatus::Confirmed);

    $this->shipments->ship($sellerOrder->fresh(), [
        ['order_item_id' => (string) $item->getKey(), 'quantity' => 1],
    ], 'Test Kargo', 'TK-2');

    expect($sellerOrder->fresh()?->status)->toBe(SellerOrderStatus::Shipped);
});

it('refuses to ship more than was ordered', function (): void {
    test()->carts->add($this->customer, $this->sku, 2);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();
    $sellerOrder = $order->sellerOrders->first();
    $item = $sellerOrder->items->first();

    // Not a generous mistake: it makes the return and refund arithmetic unsolvable,
    // because there is no order line to price the surplus against.
    expect(fn () => $this->shipments->ship($sellerOrder, [
        ['order_item_id' => (string) $item->getKey(), 'quantity' => 5],
    ], null, null))->toThrow(FulfilmentRefused::class);
});

// --- opening a return ------------------------------------------------------------

it('will not take a return for something not yet delivered', function (): void {
    test()->carts->add($this->customer, $this->sku, 1);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $order = Order::query()->latest('placed_at')->firstOrFail();
    $sellerOrder = $order->sellerOrders->first();

    expect(fn () => $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'changed_mind',
        null,
        $this->customer,
    ))->toThrow(FulfilmentRefused::class);
});

it('refuses a return after the window has closed', function (): void {
    $order = buyAndDeliver(1);
    $sellerOrder = $order->sellerOrders->first();

    $sellerOrder->forceFill(['delivered_at' => now()->subDays(30)])->save();

    expect(fn () => $this->returns->open(
        $sellerOrder->fresh(),
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'changed_mind',
        null,
        $this->customer,
    ))->toThrow(FulfilmentRefused::class);
});

it('will not let the same chair be returned twice', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();
    $item = $sellerOrder->items->first();

    $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $item->getKey(), 'quantity' => 2]],
        'damaged',
        null,
        $this->customer,
    );

    /*
     * Counting what is inside an open request, not only what has been refunded —
     * otherwise the same chair can be requested again while the first is being decided.
     */
    expect(fn () => $this->returns->open(
        $sellerOrder->fresh(),
        [['order_item_id' => (string) $item->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    ))->toThrow(FulfilmentRefused::class);
});

// --- the partial path: the gate ---------------------------------------------------

it('accepts some of a return and refunds only that', function (): void {
    $order = buyAndDeliver(4);
    $sellerOrder = $order->sellerOrders->first();
    $item = $sellerOrder->items->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $item->getKey(), 'quantity' => 3]],
        'damaged',
        'Üçü hasarlı geldi.',
        $this->customer,
    );

    expect($return->requested_minor)->toBe(300_000);

    // The seller opens the box and accepts two of the three.
    $returnItem = $return->items->first();

    $decided = $this->returns->decide(
        $return,
        accept: true,
        approved: [(string) $returnItem->getKey() => 2],
        actor: $this->sellerOwner,
        note: 'İkisi hasarlı, biri sağlam.',
    );

    expect($decided->status)->toBe(ReturnStatus::Approved)
        ->and($decided->approved_minor)->toBe(200_000);

    $this->returns->advance($decided->fresh(), ReturnStatus::InTransit, $this->customer);
    $this->returns->advance($decided->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $completed = $this->returns->advance($decided->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    expect($completed->status)->toBe(ReturnStatus::Completed);

    $refund = Refund::query()->where('return_id', $return->getKey())->firstOrFail();

    /*
     * The commission comes back too, at the rate that was charged. Keeping it would mean
     * the platform earns on a sale that did not happen and the seller funds it.
     */
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->amount_minor)->toBe(200_000)
        ->and($refund->commission_share_minor)->toBe(20_000)
        ->and($refund->seller_share_minor)->toBe(180_000);
});

it('reverses the seller share and the commission in the ledger', function (): void {
    $order = buyAndDeliver(4);
    $sellerOrder = $order->sellerOrders->first();

    $owedBefore = $this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->seller->getKey());
    $commissionBefore = $this->ledger->balanceOf(LedgerAccount::Commission);

    expect($owedBefore)->toBe(360_000)
        ->and($commissionBefore)->toBe(40_000);

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    /*
     * One chair back: 90.000 off the seller's payable and 10.000 off commission. Posting
     * the whole refund against commission would make the platform pay for the seller's
     * return.
     */
    expect($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->seller->getKey()))
        ->toBe(270_000)
        ->and($this->ledger->balanceOf(LedgerAccount::Commission))->toBe(30_000)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('puts accepted goods back on the shelf, and only when they arrive', function (): void {
    $order = buyAndDeliver(4);
    $sellerOrder = $order->sellerOrders->first();

    expect($this->stock->sellableFor($this->sku))->toBe(6);

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 2]],
        'changed_mind',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);

    // Still not back: restocking on approval would put a sofa on sale while it is in a
    // courier's van.
    expect($this->stock->sellableFor($this->sku))->toBe(6);

    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    expect($this->stock->sellableFor($this->sku))->toBe(8);
});

it('refuses a return and refunds nothing', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'changed_mind',
        null,
        $this->customer,
    );

    $rejected = $this->returns->decide($return, false, [], $this->sellerOwner, 'Kullanılmış olarak geldi.');

    expect($rejected->status)->toBe(ReturnStatus::Rejected)
        ->and($rejected->approved_minor)->toBe(0)
        ->and(Refund::query()->count())->toBe(0);
});

it('demands a reason before refusing a return', function (): void {
    $order = buyAndDeliver(1);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'changed_mind',
        null,
        $this->customer,
    );

    expect(fn () => $this->returns->decide($return, false, [], $this->sellerOwner))
        ->toThrow(FulfilmentRefused::class);
});

it('will not let a return move backwards', function (): void {
    $order = buyAndDeliver(1);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::InTransit, $this->customer);

    // A parcel already with a courier will arrive whatever anybody now wants.
    expect(fn () => $this->returns->advance($return->fresh(), ReturnStatus::Cancelled, $this->customer))
        ->toThrow(FulfilmentRefused::class);
});

// --- provider failure: the other half of the gate ------------------------------------

it('records a refused refund as retryable rather than losing it', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();

    // The provider refuses: a payment too old to refund, which is an ordinary answer
    // rather than an error, and asked for by flagging the intent.
    PaymentIntent::query()->whereKey($order->payment_intent_id)
        ->update(['details' => json_encode(['refuse_refunds' => true])]);

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    $refund = Refund::query()->where('return_id', $return->getKey())->firstOrFail();

    /*
     * A state, not a swallowed exception. The customer is owed the money either way, and
     * a return that hid the failure would leave them waiting on nothing.
     */
    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_reason)->not->toBeNull()
        // Nothing posted: the books must not say money went back when it did not.
        ->and($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->seller->getKey()))
        ->toBe(180_000)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('retries a failed refund and posts the reversal then', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();

    $intent = PaymentIntent::query()->findOrFail($order->payment_intent_id);

    PaymentIntent::query()->whereKey($intent->getKey())
        ->update(['details' => json_encode(['refuse_refunds' => true])]);

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    $refund = Refund::query()->where('return_id', $return->getKey())->firstOrFail();

    expect($refund->status)->toBe(RefundStatus::Failed);

    // The provider comes back.
    PaymentIntent::query()->whereKey($intent->getKey())->update(['details' => json_encode([])]);

    $retried = $this->refunds->process($refund->fresh(), $this->operator);

    expect($retried->status)->toBe(RefundStatus::Succeeded)
        ->and($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->seller->getKey()))
        ->toBe(90_000)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('refunds a return once however many times it is completed', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    // Completing again — a retried job, a double-clicked button.
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    expect(Refund::query()->where('return_id', $return->getKey())->count())->toBe(1)
        ->and($this->ledger->balanceOf(LedgerAccount::SellerPayable, (string) $this->seller->getKey()))
        ->toBe(90_000);
});

// --- manual refunds ------------------------------------------------------------------

it('issues a goodwill refund with no return behind it', function (): void {
    $order = buyAndDeliver(2);

    $refund = $this->refunds->openManual(
        $order,
        25_000,
        'Geç teslimat için jest.',
        $this->operator,
        $order->sellerOrders->first(),
    );

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        // Split at the seller order's own rate, so the reversal stays proportionate.
        ->and($refund->commission_share_minor)->toBe(2_500)
        ->and($this->ledger->isBalanced())->toBeTrue();
});

it('will not refund more than the order was worth', function (): void {
    $order = buyAndDeliver(1);

    expect(fn () => $this->refunds->openManual($order, 500_000, 'Fazla', $this->operator))
        ->toThrow(FulfilmentRefused::class);
});

it('counts what has already gone back', function (): void {
    $order = buyAndDeliver(2);

    $this->refunds->openManual($order, 150_000, 'Kısmi iade', $this->operator);

    expect($this->refunds->refundableMinor($order->fresh()))->toBe(50_000);

    expect(fn () => $this->refunds->openManual($order->fresh(), 60_000, 'Fazla', $this->operator))
        ->toThrow(FulfilmentRefused::class);
});

// --- settlement holds: E2E-09 ----------------------------------------------------------

it('blocks a payout while a return is open, and releases it when resolved', function (): void {
    $order = buyAndDeliver(2);
    $sellerOrder = $order->sellerOrders->first();

    /*
     * Delivered ten days ago: inside both the return window and the settlement hold, which
     * is where E2E-09 actually happens. The two windows are the same length on purpose —
     * the hold exists to cover the window — so a return can only ever be opened against an
     * order that is not yet eligible.
     */
    $sellerOrder->forceFill(['delivered_at' => now()->subDays(10)])->save();

    $return = $this->returns->open(
        $sellerOrder->fresh(),
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    // Aged past the hold with the return still open.
    $sellerOrder->fresh()?->forceFill(['delivered_at' => now()->subDays(20)])->save();
    app(OrderAccounting::class)->rebuildBalance((string) $this->seller->getKey());

    /*
     * Paying for goods on their way back means chasing money from somebody who has already
     * spent it — and a request nobody has looked at yet is exactly the case where paying
     * out would be most embarrassing.
     */
    expect($this->eligibility->eligible((string) $this->seller->getKey()))->toHaveCount(0)
        ->and($this->eligibility->explain($sellerOrder->fresh()))->toContain('Açık bir iade talebi');

    $this->returns->decide($return, false, [], $this->sellerOwner, 'Ürün kullanılmış.');

    // Resolved, so the money is free again.
    expect($this->eligibility->eligible((string) $this->seller->getKey()))->toHaveCount(1);
});

it('recalculates the settlement figure after a completed return', function (): void {
    $order = buyAndDeliver(4);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $this->returns->decide($return, true, [], $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Received, $this->sellerOwner);
    $this->returns->advance($return->fresh(), ReturnStatus::Completed, $this->sellerOwner);

    $sellerOrder->fresh()?->forceFill(['delivered_at' => now()->subDays(20)])->save();
    app(OrderAccounting::class)->rebuildBalance((string) $this->seller->getKey());

    // Three chairs' worth, not four: the ledger is the authority and the refund reduced it.
    expect($this->eligibility->owedTotal((string) $this->seller->getKey()))->toBe(270_000);
});

it('keeps one customer out of another return', function (): void {
    $order = buyAndDeliver(1);
    $sellerOrder = $order->sellerOrders->first();

    $return = $this->returns->open(
        $sellerOrder,
        [['order_item_id' => (string) $sellerOrder->items->first()->getKey(), 'quantity' => 1]],
        'damaged',
        null,
        $this->customer,
    );

    $stranger = User::factory()->create();
    $stranger->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($stranger)
        ->getJson('/api/v1/returns/'.$return->reference)
        ->assertNotFound();

    $this->actingAs($this->customer)
        ->getJson('/api/v1/returns/'.$return->reference)
        ->assertOk()
        ->assertJsonPath('data.reference', $return->reference);

    expect(ReturnRequest::query()->count())->toBe(1);
});

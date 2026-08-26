<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Exceptions\OrderRefused;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\OrderStatusChange;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Services\CheckoutFulfiller;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * One payment, one order, one seller order per seller.
 *
 * The gate is E2E-06 from 15_CRITICAL_E2E_SCENARIOS.md, and it is really a single claim:
 * a marketplace order is two things at once, and both have to be true at the same time.
 * The customer bought one thing and will ask about it by one number; each seller received
 * a separate instruction with their own parcel, their own status and their own money.
 *
 * Everything else here follows from the second claim being about *records of events*
 * rather than views over the catalogue — which is why the snapshots are tested by
 * changing the world afterwards and checking that the order did not move.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->sellerA] = makeApprovedSeller('Koltuk Dünyası A.Ş.', 'koltuk-dunyasi');
    [$this->sellerB] = makeApprovedSeller('Aydınlatma Ltd.', 'aydinlatma-ltd');

    $category = makeCategory('Oturma', 'oturma-siparis', 'living_room');

    $this->sofa = makeProduct($this->sellerA, $category, [
        'name' => 'Üç kişilik kanepe',
        'description' => 'Sipariş testleri için kanepe.',
        'price_minor' => 1_500_000,
        'stock_quantity' => 4,
    ]);

    $this->lamp = makeProduct($this->sellerB, $category, [
        'name' => 'Ayaklı lamba',
        'description' => 'Sipariş testleri için lamba.',
        'price_minor' => 250_000,
        'stock_quantity' => 6,
    ]);

    $this->sofaSku = $this->sofa->skus->first();
    $this->lampSku = $this->lamp->skus->first();

    $this->stock->adjust($this->stock->itemFor($this->sofaSku), 4, MovementType::Receipt);
    $this->stock->adjust($this->stock->itemFor($this->lampSku), 6, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'phone' => '+905551112233',
        'city' => 'İstanbul',
        'district' => 'Kadıköy',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
    ]);
});

/** Buys a sofa from one seller and a lamp from another, and pays. */
function placeMultiSellerOrder(int $sofas = 1, int $lamps = 2): Order
{
    test()->carts->add(test()->customer, test()->sofaSku, $sofas);
    test()->carts->add(test()->customer, test()->lampSku, $lamps);

    $session = test()->checkout->openCart(test()->customer, []);

    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    return Order::query()->latest('placed_at')->firstOrFail();
}

// --- the split: the gate ------------------------------------------------------

it('makes one order and one seller order per seller', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    expect(Order::query()->count())->toBe(1)
        ->and($order->sellerOrders()->count())->toBe(2)
        ->and($order->items()->count())->toBe(2)
        ->and($order->status)->toBe(OrderStatus::Paid);

    $bySeller = $order->sellerOrders->keyBy('seller_id');

    // Each seller's total is their own goods, not a share of the basket.
    expect($bySeller[$this->sellerA->getKey()]->total_minor)->toBe(1_500_000)
        ->and($bySeller[$this->sellerB->getKey()]->total_minor)->toBe(500_000)
        ->and($order->grand_total_minor)->toBe(2_000_000);
});

it('gives the customer one number and each seller their own', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    expect($order->order_number)->toStartWith('RC-');

    foreach ($order->sellerOrders as $sellerOrder) {
        /*
         * Derived from the master, so a seller reading out "…-2" and a customer reading
         * out the master number are obviously talking about the same order. Two unrelated
         * sequences would destroy exactly the thing support needs.
         */
        expect($sellerOrder->seller_order_number)->toStartWith($order->order_number.'-');
    }
});

it('tells each seller about their own part and nobody else', function (): void {
    Notification::fake();

    placeMultiSellerOrder();

    Notification::assertCount(2);
});

// --- snapshots ----------------------------------------------------------------

it('freezes the name and the price against later edits', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    $item = $order->items->firstWhere('sku_id', $this->sofaSku->getKey());

    expect($item?->product_name)->toBe('Üç kişilik kanepe')
        ->and($item?->unit_price_minor)->toBe(1_500_000);

    // The world moves on.
    $this->sofa->forceFill(['name' => 'Tamamen başka bir ürün'])->save();
    $this->sofaSku->forceFill(['list_price_minor' => 9_900_000])->save();

    /*
     * An order that renders differently after a catalogue edit is not a record of
     * anything — and an invoice that changes retroactively is worse than no invoice.
     */
    expect($item?->fresh()?->product_name)->toBe('Üç kişilik kanepe')
        ->and($item?->fresh()?->unit_price_minor)->toBe(1_500_000);
});

it('snapshots the commission at order time', function (): void {
    Notification::fake();

    $this->sellerA->forceFill(['default_commission_bps' => 1_000])->save();

    $order = placeMultiSellerOrder();

    $sellerOrder = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    expect($sellerOrder?->commission_minor)->toBe(150_000)
        ->and($sellerOrder?->payableMinor())->toBe(1_350_000);

    // A renegotiated rate must not change what was earned on a sale already made.
    $this->sellerA->forceFill(['default_commission_bps' => 2_500])->save();

    expect($sellerOrder?->fresh()?->commission_minor)->toBe(150_000);
});

it('keeps the address as text rather than a link', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    UserAddress::query()->where('user_id', $this->customer->getKey())
        ->update(['address_line1' => 'Bambaşka bir sokak 9']);

    expect($order->fresh()?->shipping_address['address_line1'] ?? null)->toBe('Bağdat Caddesi 100');
});

// --- duplicates ----------------------------------------------------------------

it('makes one order however many times the payment is confirmed', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    // The fulfiller run again, the way a duplicate webhook would run it.
    app(CheckoutFulfiller::class)
        ->fulfil($order->payment ?? throw new RuntimeException('payment missing'));

    expect(Order::query()->count())->toBe(1)
        ->and(SellerOrder::query()->count())->toBe(2);
});

// --- the status machine ----------------------------------------------------------

it('derives the master status from its parts', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $parts = $order->sellerOrders;

    $this->statuses->advance($parts[0], SellerOrderStatus::Confirmed);

    expect($order->fresh()?->status)->toBe(OrderStatus::Processing);

    $this->statuses->advance($parts[0]->fresh(), SellerOrderStatus::Shipped);

    /*
     * Not "shipped". On a two-seller order, telling a customer their order has shipped
     * when one parcel is still on a shelf is a status that is technically true and
     * practically a lie.
     */
    expect($order->fresh()?->status)->toBe(OrderStatus::PartiallyShipped);

    $this->statuses->advance($parts[1], SellerOrderStatus::Confirmed);
    $this->statuses->advance($parts[1]->fresh(), SellerOrderStatus::Shipped);

    expect($order->fresh()?->status)->toBe(OrderStatus::Shipped);

    $this->statuses->advance($parts[0]->fresh(), SellerOrderStatus::Delivered);
    $this->statuses->advance($parts[1]->fresh(), SellerOrderStatus::Delivered);

    expect($order->fresh()?->status)->toBe(OrderStatus::Delivered)
        ->and($order->fresh()?->completed_at)->not->toBeNull();
});

it('does not strand an order behind a cancelled part', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $parts = $order->sellerOrders;

    $this->statuses->advance($parts[1], SellerOrderStatus::Cancelled, null, 'seller', 'Stok hasarlı çıktı.');
    $this->statuses->advance($parts[0], SellerOrderStatus::Confirmed);
    $this->statuses->advance($parts[0]->fresh(), SellerOrderStatus::Shipped);

    // The cancelled part is excluded from "have they all shipped" — otherwise the order
    // waits forever on a parcel nobody is sending.
    expect($order->fresh()?->status)->toBe(OrderStatus::Shipped);
});

it('cancels the whole order when every part is cancelled', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    foreach ($order->sellerOrders as $part) {
        $this->statuses->advance($part, SellerOrderStatus::Cancelled, null, 'seller', 'Tedarik edilemedi.');
    }

    expect($order->fresh()?->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()?->cancelled_at)->not->toBeNull();
});

it('refuses a move the order cannot make', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $part = $order->sellerOrders->first();

    $this->statuses->advance($part, SellerOrderStatus::Confirmed);
    $this->statuses->advance($part->fresh(), SellerOrderStatus::Shipped);

    /*
     * What happens after a parcel leaves is a return, with a different set of rights. A
     * seller pressing "cancel" on something already in a van would leave the money and
     * the goods in disagreement.
     */
    expect(fn () => $this->statuses->advance($part->fresh(), SellerOrderStatus::Cancelled, null, 'seller', 'Vazgeçtim'))
        ->toThrow(OrderRefused::class);
});

it('demands a reason for a cancellation', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();

    expect(fn () => $this->statuses->advance($order->sellerOrders->first(), SellerOrderStatus::Cancelled))
        ->toThrow(OrderRefused::class);
});

it('treats being told the same status twice as a no-op', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $part = $order->sellerOrders->first();

    $this->statuses->advance($part, SellerOrderStatus::Confirmed);
    $this->statuses->advance($part->fresh(), SellerOrderStatus::Confirmed);

    // One transition recorded, not two: a double-clicked button is not an event.
    expect(OrderStatusChange::query()
        ->where('seller_order_id', $part->getKey())
        ->where('to_status', 'confirmed')
        ->count())->toBe(1);
});

// --- stock and the record --------------------------------------------------------

it('puts cancelled goods back on the shelf', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder(sofas: 2, lamps: 1);

    expect($this->stock->sellableFor($this->sofaSku))->toBe(2);

    $part = $order->sellerOrders->firstWhere('seller_id', $this->sellerA->getKey());

    $this->statuses->advance($part, SellerOrderStatus::Cancelled, null, 'seller', 'Depoda hasar bulundu.');

    /*
     * The stock left when the payment was captured, so cancelling has to put it back or
     * the warehouse and the ledger disagree — and that only surfaces weeks later as a
     * sale nobody can fulfil.
     */
    expect($this->stock->sellableFor($this->sofaSku))->toBe(4);
});

it('records every change with who and why', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $part = $order->sellerOrders->first();

    $this->statuses->advance($part, SellerOrderStatus::Confirmed, $this->customer, 'operator', 'Telefonla onaylandı');

    $entry = OrderStatusChange::query()
        ->where('seller_order_id', $part->getKey())
        ->where('to_status', 'confirmed')
        ->firstOrFail();

    expect($entry->from_status)->toBe('awaiting_confirmation')
        ->and($entry->changed_by)->toBe($this->customer->getKey())
        ->and($entry->actor_role)->toBe('operator')
        ->and($entry->reason)->toBe('Telefonla onaylandı');
});

it('will not let the history be edited', function (): void {
    Notification::fake();

    $order = placeMultiSellerOrder();
    $entry = OrderStatusChange::query()->where('order_id', $order->getKey())->firstOrFail();

    // A table that can be edited cannot answer "when did this become shipped, and who
    // said so" — which is the question every dispute starts with.
    expect(fn () => DB::table('order_status_history')
        ->where('id', $entry->getKey())
        ->update(['to_status' => 'delivered']))
        ->toThrow(QueryException::class);
});

it('does not turn a credit purchase into an order', function (): void {
    Notification::fake();

    $package = CreditPackage::query()->create([
        'code' => 'siparis-paket',
        'name' => 'Paket',
        'credits' => 40,
        'price_minor' => 19_900,
        'currency' => 'TRY',
    ]);

    $session = $this->checkout->openCredits($this->customer, $package);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    // A credit package has no seller and no parcel; it was fulfilled by the credit ledger
    // and has no business being an order.
    expect(Order::query()->count())->toBe(0);
});

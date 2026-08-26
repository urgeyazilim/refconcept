<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Fulfilment\Models\Refund;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The endpoints for shipping, returning and refunding.
 *
 * The isolation is the interesting part: a customer sees only their own returns, a seller
 * only the ones coming back to them, and refunds are finance's — behind the settle
 * permission, because sending money back is still sending money.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller, $this->sellerOwner] = makeApprovedSeller('Kargo A.Ş.', 'kargo-as');

    $product = makeProduct($this->seller, makeCategory('Koltuk', 'koltuk-kargo', 'living_room'), [
        'name' => 'Kargo koltuğu',
        'description' => 'Kargo ve iade uç noktaları.',
        'price_minor' => 200_000,
        'stock_quantity' => 6,
    ]);

    $this->sku = $product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 6, MovementType::Receipt);

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
    $this->operator->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->operator, SystemRole::Operator);

    $this->carts->add($this->customer, $this->sku, 3);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $this->order = Order::query()->latest('placed_at')->firstOrFail();
    $this->sellerOrder = $this->order->sellerOrders->first();
    $this->item = $this->sellerOrder->items->first();
});

/** Walks the order to delivered through the shipping endpoints. */
function deliverThroughApi(): void
{
    test()->statuses->advance(test()->sellerOrder, SellerOrderStatus::Confirmed);

    $shipped = test()->actingAs(test()->sellerOwner)
        ->postJson('/api/v1/seller/orders/'.test()->sellerOrder->seller_order_number.'/shipments', [
            'carrier' => 'Test Kargo',
            'tracking_number' => 'TK-9911',
            'items' => [['order_item_id' => (string) test()->item->getKey(), 'quantity' => 3]],
        ])
        ->assertCreated();

    $shipmentId = $shipped->json('data.shipment.id');

    test()->actingAs(test()->sellerOwner)
        ->postJson('/api/v1/seller/orders/'.test()->sellerOrder->seller_order_number.'/shipments/'.$shipmentId.'/delivered')
        ->assertOk();
}

it('records a parcel and moves the order with it', function (): void {
    $this->statuses->advance($this->sellerOrder, SellerOrderStatus::Confirmed);

    $response = $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments', [
            'carrier' => 'Test Kargo',
            'tracking_number' => 'TK-1',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 2]],
        ])
        ->assertCreated();

    // Two of three: the order is not shipped yet, and saying otherwise would confuse the
    // customer for a week.
    expect($response->json('data.shipment.item_count'))->toBe(2)
        ->and($response->json('data.seller_order.status'))->toBe('confirmed');
});

it('tells the seller what is still on the shelf', function (): void {
    $this->statuses->advance($this->sellerOrder, SellerOrderStatus::Confirmed);

    $ordered = $this->item->quantity;

    $before = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments')
        ->assertOk();

    expect($before->json('meta.pending.0.remaining'))->toBe($ordered);

    $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments', [
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertCreated();

    /*
     * Sent rather than left to the caller. The alternative is every client subtracting
     * shipment lines from order lines itself — arithmetic that has to be right in three
     * places, and that a seller would otherwise do in their head while looking at a
     * screen that already knows the answer.
     */
    $after = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments')
        ->assertOk();

    expect($after->json('meta.pending.0.remaining'))->toBe($ordered - 1)
        ->and($after->json('meta.pending.0.ordered'))->toBe($ordered);
});

it('drops a line from the pending list once it has all gone', function (): void {
    $this->statuses->advance($this->sellerOrder, SellerOrderStatus::Confirmed);

    $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments', [
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => $this->item->quantity]],
        ])
        ->assertCreated();

    // A finished line is dropped rather than sent as a zero: a seller shipping the last of
    // four parcels should see one row, not hunt for it among three that are done.
    $response = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments')
        ->assertOk();

    expect($response->json('meta.pending'))->toBe([]);
});

it('will not let one seller ship another seller order', function (): void {
    [, $otherOwner] = makeApprovedSeller('Başka Kargo', 'baska-kargo');

    $this->actingAs($otherOwner)
        ->postJson('/api/v1/seller/orders/'.$this->sellerOrder->seller_order_number.'/shipments', [
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertNotFound();
});

it('opens a return and shows it to the customer', function (): void {
    deliverThroughApi();

    $response = $this->actingAs($this->customer)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'damaged',
            'reason_note' => 'Biri hasarlı geldi.',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.status'))->toBe('requested')
        ->and($response->json('data.requested_minor'))->toBe(200_000)
        ->and($response->json('data.message'))->toContain('satıcıya iletildi');
});

it('refuses a return for an order that is not the customer own', function (): void {
    deliverThroughApi();

    $stranger = User::factory()->create();
    $stranger->forceFill(['email_verified_at' => now()])->save();

    // 404 rather than 403: whether somebody else's order exists is not a thing to confirm.
    $this->actingAs($stranger)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'damaged',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertNotFound();
});

it('lets the seller accept part of a request', function (): void {
    deliverThroughApi();

    $created = $this->actingAs($this->customer)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'damaged',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 3]],
        ])
        ->assertCreated();

    $reference = $created->json('data.reference');
    $returnItemId = $created->json('data.items.0.id');

    $decided = $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/returns/'.$reference.'/decision', [
            'accept' => true,
            'approved' => [$returnItemId => 2],
            'note' => 'İkisi hasarlı.',
        ])
        ->assertOk();

    // Two of three accepted, and the amount follows the decision rather than the request.
    expect($decided->json('data.status'))->toBe('approved')
        ->and($decided->json('data.approved_minor'))->toBe(400_000);
});

it('demands a reason before a seller refuses a return', function (): void {
    deliverThroughApi();

    $created = $this->actingAs($this->customer)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'changed_mind',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertCreated();

    $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/returns/'.$created->json('data.reference').'/decision', [
            'accept' => false,
        ])
        ->assertStatus(422);
});

it('completes a return and issues the refund', function (): void {
    deliverThroughApi();

    $created = $this->actingAs($this->customer)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'damaged',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertCreated();

    $reference = $created->json('data.reference');

    $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/returns/'.$reference.'/decision', ['accept' => true])
        ->assertOk();

    $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/returns/'.$reference.'/status', ['status' => 'received'])
        ->assertOk();

    $completed = $this->actingAs($this->sellerOwner)
        ->postJson('/api/v1/seller/returns/'.$reference.'/status', ['status' => 'completed'])
        ->assertOk();

    // The refund is its own object with its own status, reported alongside the return.
    expect($completed->json('data.status'))->toBe('completed')
        ->and($completed->json('data.refund.status'))->toBe('succeeded')
        ->and($completed->json('data.refund.amount_minor'))->toBe(200_000);
});

it('keeps one seller out of another seller returns', function (): void {
    deliverThroughApi();

    $created = $this->actingAs($this->customer)
        ->postJson('/api/v1/returns', [
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'reason_code' => 'damaged',
            'items' => [['order_item_id' => (string) $this->item->getKey(), 'quantity' => 1]],
        ])
        ->assertCreated();

    [, $otherOwner] = makeApprovedSeller('Rakip A.Ş.', 'rakip-as');

    $this->actingAs($otherOwner)
        ->postJson('/api/v1/seller/returns/'.$created->json('data.reference').'/decision', ['accept' => true])
        ->assertNotFound();

    $list = $this->actingAs($otherOwner)->getJson('/api/v1/seller/returns')->assertOk();

    expect($list->json('data'))->toHaveCount(0);
});

// --- finance ------------------------------------------------------------------

it('issues a goodwill refund and says what is left', function (): void {
    $before = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/refunds/orders/'.$this->order->order_number)
        ->assertOk();

    expect($before->json('data.refundable_minor'))->toBe(600_000);

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/refunds', [
            'order_number' => $this->order->order_number,
            'seller_order_number' => $this->sellerOrder->seller_order_number,
            'amount_minor' => 50_000,
            'reason' => 'Geç teslimat için jest.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'succeeded');

    $after = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/refunds/orders/'.$this->order->order_number)
        ->assertOk();

    expect($after->json('data.refundable_minor'))->toBe(550_000);
});

it('demands a reason for a manual refund', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/refunds', [
            'order_number' => $this->order->order_number,
            'amount_minor' => 10_000,
        ])
        ->assertStatus(422);
});

it('keeps a seller out of the refund endpoints', function (): void {
    $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/admin/refunds')
        ->assertForbidden();

    expect(Refund::query()->count())->toBe(0)
        ->and(ReturnRequest::query()->count())->toBe(0);
});

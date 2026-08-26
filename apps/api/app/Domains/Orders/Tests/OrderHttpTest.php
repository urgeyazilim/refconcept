<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The endpoints, and the isolation they exist to enforce.
 *
 * Two sellers share one customer's basket. Everything below is about neither of them being
 * able to see the other's half of it, and about the customer seeing both halves as one
 * order — which is the same data read two entirely different ways.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->sellerA, $this->ownerA] = makeApprovedSeller('Kanepe A.Ş.', 'kanepe-http');
    [$this->sellerB, $this->ownerB] = makeApprovedSeller('Lamba A.Ş.', 'lamba-http');

    $category = makeCategory('Oturma', 'oturma-http', 'living_room');

    $sofa = makeProduct($this->sellerA, $category, [
        'name' => 'HTTP kanepe',
        'description' => 'Sipariş uç noktası testleri.',
        'price_minor' => 900_000,
        'stock_quantity' => 3,
    ]);

    $lamp = makeProduct($this->sellerB, $category, [
        'name' => 'HTTP lamba',
        'description' => 'Sipariş uç noktası testleri.',
        'price_minor' => 150_000,
        'stock_quantity' => 5,
    ]);

    $this->sofaSku = $sofa->skus->first();
    $this->lampSku = $lamp->skus->first();

    $this->stock->adjust($this->stock->itemFor($this->sofaSku), 3, MovementType::Receipt);
    $this->stock->adjust($this->stock->itemFor($this->lampSku), 5, MovementType::Receipt);

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

    $this->carts->add($this->customer, $this->sofaSku, 1);
    $this->carts->add($this->customer, $this->lampSku, 2);

    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $this->order = Order::query()->latest('placed_at')->firstOrFail();
});

it('shows the customer one order made of several parcels', function (): void {
    $response = $this->actingAs($this->customer)
        ->getJson('/api/v1/orders/'.$this->order->order_number)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.order_number'))->toBe($this->order->order_number)
        ->and($response->json('data.sellers'))->toHaveCount(2)
        ->and($response->json('data.totals.grand_total_minor'))->toBe(1_200_000)
        // The customer's vocabulary: "onay bekliyor" is an internal state and reads as a
        // problem to somebody who has already paid.
        ->and($response->json('data.sellers.0.status_label'))->toBe('Hazırlanıyor');
});

it('will not show one customer another order', function (): void {
    $stranger = User::factory()->create();
    $stranger->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($stranger)
        ->getJson('/api/v1/orders/'.$this->order->order_number)
        ->assertNotFound();
});

it('gives each seller only their own half', function (): void {
    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/seller/orders')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.total_minor'))->toBe(900_000);

    $other = $this->actingAs($this->ownerB)
        ->getJson('/api/v1/seller/orders')
        ->assertOk();

    expect($other->json('data.0.total_minor'))->toBe(300_000);
});

it('will not let one seller open another seller order', function (): void {
    $theirs = SellerOrder::query()->where('seller_id', $this->sellerB->getKey())->firstOrFail();

    // 404 rather than 403: whether a competitor has an order is not something to confirm.
    $this->actingAs($this->ownerA)
        ->getJson('/api/v1/seller/orders/'.$theirs->seller_order_number)
        ->assertNotFound();
});

it('gives the seller the address and nothing about the rest of the basket', function (): void {
    $mine = SellerOrder::query()->where('seller_id', $this->sellerA->getKey())->firstOrFail();

    $response = $this->actingAs($this->ownerA)
        ->getJson('/api/v1/seller/orders/'.$mine->seller_order_number)
        ->assertOk();

    expect($response->json('data.recipient.address_line1'))->toBe('Bağdat Caddesi 100')
        ->and($response->json('data.items'))->toHaveCount(1)
        // A seller has no business knowing who else the customer bought from.
        ->and($response->json('data'))->not->toHaveKey('customer_email');
});

it('lets a seller move their own order along', function (): void {
    $mine = SellerOrder::query()->where('seller_id', $this->sellerA->getKey())->firstOrFail();

    $this->actingAs($this->ownerA)
        ->postJson('/api/v1/seller/orders/'.$mine->seller_order_number.'/status', ['status' => 'confirmed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $this->actingAs($this->ownerA)
        ->postJson('/api/v1/seller/orders/'.$mine->seller_order_number.'/status', ['status' => 'shipped'])
        ->assertOk();

    // The customer's order says partly, not wholly: the lamp has not left.
    $this->actingAs($this->customer)
        ->getJson('/api/v1/orders/'.$this->order->order_number)
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_shipped');
});

it('refuses a move the order cannot make, and names both states', function (): void {
    $mine = SellerOrder::query()->where('seller_id', $this->sellerA->getKey())->firstOrFail();

    $response = $this->actingAs($this->ownerA)
        ->postJson('/api/v1/seller/orders/'.$mine->seller_order_number.'/status', ['status' => 'delivered'])
        ->assertStatus(409);

    // Names what it is now, so a seller on a stale screen learns something.
    expect($response->json('message'))->toContain('Onay bekliyor');
});

it('demands a reason before a cancellation', function (): void {
    $mine = SellerOrder::query()->where('seller_id', $this->sellerA->getKey())->firstOrFail();

    $this->actingAs($this->ownerA)
        ->postJson('/api/v1/seller/orders/'.$mine->seller_order_number.'/status', ['status' => 'cancelled'])
        ->assertStatus(422);

    $this->actingAs($this->ownerA)
        ->postJson('/api/v1/seller/orders/'.$mine->seller_order_number.'/status', [
            'status' => 'cancelled',
            'reason' => 'Depoda hasar bulundu.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('keeps a customer out of the seller endpoints', function (): void {
    $this->actingAs($this->customer)
        ->getJson('/api/v1/seller/orders')
        ->assertForbidden();
});

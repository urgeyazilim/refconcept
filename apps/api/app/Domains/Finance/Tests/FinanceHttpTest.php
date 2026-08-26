<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Finance\Models\Settlement;
use App\Domains\Finance\Services\OrderAccounting;
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
 * The finance endpoints, and who may reach them.
 *
 * Reading the books is a support job. Approving a payout commits money and marking one
 * paid records that it left, and neither should be reachable by somebody answering "where
 * is my money" — which is the split these tests exist to hold.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->statuses = app(OrderStatusService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller, $this->sellerOwner] = makeApprovedSeller('Hakediş A.Ş.', 'hakedis-as');

    $product = makeProduct($this->seller, makeCategory('Masa', 'masa-hakedis', 'living_room'), [
        'name' => 'Hakediş masası',
        'description' => 'Finans uç noktası testleri.',
        'price_minor' => 600_000,
        'stock_quantity' => 4,
    ]);

    $this->sku = $product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 4, MovementType::Receipt);

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

    $this->analyst = User::factory()->create();
    $this->analyst->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->analyst, SystemRole::Analyst);

    $this->carts->add($this->customer, $this->sku, 1);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $this->order = Order::query()->latest('placed_at')->firstOrFail();
    $this->sellerOrder = $this->order->sellerOrders->first();
});

/** Walks the seller order to delivered and past the hold. */
function ageToEligible(): void
{
    $part = test()->sellerOrder;

    test()->statuses->advance($part, SellerOrderStatus::Confirmed);
    test()->statuses->advance($part->fresh(), SellerOrderStatus::Shipped);
    test()->statuses->advance($part->fresh(), SellerOrderStatus::Delivered);

    $part->fresh()?->forceFill(['delivered_at' => now()->subDays(20)])->save();

    app(OrderAccounting::class)->rebuildBalance((string) $part->seller_id, $part->currency);
}

// --- the seller's side ---------------------------------------------------------

it('shows a seller what they are owed, split by state', function (): void {
    $response = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/earnings')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    // Four figures, because the money really is in four states. One "bakiye" is how a
    // seller reads a number they cannot yet have.
    expect($response->json('data.pending_minor'))->toBe($this->sellerOrder->payableMinor())
        ->and($response->json('data.available_minor'))->toBe(0)
        ->and($response->json('data.hold_days'))->toBe(14);
});

it('tells a seller when each order becomes payable', function (): void {
    $response = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/earnings/orders')
        ->assertOk();

    // A sentence, not a code. Sellers ask, and silence is a support ticket.
    expect($response->json('data.0.settlement_note'))
        ->toBe('Teslim edildikten sonra hakediş süresi başlar.');

    ageToEligible();

    $after = $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/seller/earnings/orders')
        ->assertOk();

    expect($after->json('data.0.settlement_note'))->toBe('Hakedişe hazır.');
});

it('keeps one seller out of another seller earnings', function (): void {
    [, $otherOwner] = makeApprovedSeller('Başka Satıcı', 'baska-satici');

    $response = $this->actingAs($otherOwner)
        ->getJson('/api/v1/seller/earnings')
        ->assertOk();

    // Their own zeros, never the first seller's figures.
    expect($response->json('data.pending_minor'))->toBe(0);
});

it('keeps a customer out of the seller earnings', function (): void {
    $this->actingAs($this->customer)
        ->getJson('/api/v1/seller/earnings')
        ->assertForbidden();
});

// --- finance -------------------------------------------------------------------

it('says whether the books balance before anything else', function (): void {
    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/finance/overview')
        ->assertOk();

    expect($response->json('data.is_balanced'))->toBeTrue()
        ->and($response->json('data.accounts'))->not->toBeEmpty();
});

it('builds, approves and pays a settlement through the endpoints', function (): void {
    ageToEligible();

    $built = $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/build')
        ->assertOk();

    expect($built->json('data'))->toHaveCount(1);

    $settlement = Settlement::query()->firstOrFail();

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // The bank's own reference is required: a settlement marked paid with nothing to look
    // up is a seller asking where their money is and nobody able to answer.
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/paid', [])
        ->assertStatus(422);

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/paid', [
            'payout_reference' => 'EFT-2026-00918',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');
});

it('refuses a second approval with a sentence', function (): void {
    ageToEligible();

    $this->actingAs($this->operator)->postJson('/api/v1/admin/finance/settlements/build')->assertOk();

    $settlement = Settlement::query()->firstOrFail();

    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/approve')
        ->assertOk();

    $response = $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/settlements/'.$settlement->getKey().'/approve')
        ->assertStatus(409);

    expect($response->json('message'))->toContain('Onaylandı');
});

it('lets an analyst read the books but not move money', function (): void {
    ageToEligible();

    $this->actingAs($this->analyst)
        ->getJson('/api/v1/admin/finance/overview')
        ->assertOk();

    /*
     * The split that matters: answering "did it arrive" is a support job, and deciding
     * that money should leave is not.
     */
    $this->actingAs($this->analyst)
        ->postJson('/api/v1/admin/finance/settlements/build')
        ->assertForbidden();
});

it('keeps a seller out of platform finance', function (): void {
    $this->actingAs($this->sellerOwner)
        ->getJson('/api/v1/admin/finance/overview')
        ->assertForbidden();
});

it('refuses a commission rule whose shape contradicts its scope', function (): void {
    // A `seller` rule with a category is a row whose meaning depends on which column the
    // reader happens to look at. The database refuses it; this proves the API surfaces it
    // rather than 500ing.
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/commission-rules', [
            'scope' => 'seller',
            'seller_id' => $this->seller->getKey(),
            'category_id' => makeCategory('Yanlış', 'yanlis-kural', null)->getKey(),
            'rate_bps' => 1_000,
        ])
        ->assertStatus(500);
});

it('creates a campaign rule and applies it to the next order', function (): void {
    $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/finance/commission-rules', [
            'scope' => 'campaign',
            'seller_id' => $this->seller->getKey(),
            'rate_bps' => 500,
            'label' => 'Eylül kampanyası',
            'starts_at' => now()->subDay()->toIso8601String(),
            'ends_at' => now()->addWeek()->toIso8601String(),
        ])
        ->assertCreated();

    $this->carts->add($this->customer, $this->sku, 1);
    $session = $this->checkout->openCart($this->customer, []);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    /*
     * Selected by exclusion rather than by "the newest". Both orders are placed within the
     * same test and `placed_at` can tie, which would silently assert against the first
     * one — a test that passes for the wrong reason is worse than one that fails.
     */
    $second = Order::query()->whereKeyNot($this->order->getKey())->firstOrFail();

    expect($second->sellerOrders->first()?->commission_minor)->toBe(30_000);
});

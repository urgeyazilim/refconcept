<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Orders\Services\OrderStatusService;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Services\CheckoutService;
use Database\Seeders\CommissionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * The seller's front page, and what it leads with.
 *
 * A dashboard that opens with a revenue figure looks impressive and answers nothing: the
 * seller already knows roughly what they sold. What they do not know is that four orders
 * have been sitting unconfirmed since Friday — so the queue is the part that has to be
 * right, and these tests check the counts move when the work does.
 *
 * The other half is isolation. Every figure is scoped to the caller's own seller, and
 * there is no id in the path, so "whose dashboard" cannot be asked at all.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CommissionSeeder::class);

    Notification::fake();

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller, $this->owner] = makeApprovedSeller('Pano Mobilya', 'pano-mobilya');
    $this->owner->forceFill(['email_verified_at' => now()])->save();

    $this->product = makeProduct($this->seller, makeCategory('Koltuk', 'koltuk-pano', 'living_room'), [
        'name' => 'Pano koltuğu',
        'description' => 'Pano testleri.',
        'price_minor' => 300_000,
        'stock_quantity' => 10,
    ]);

    $this->sku = $this->product->skus->first();
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
});

/** Buys and pays, leaving one seller order awaiting confirmation. */
function buyFromSeller(int $quantity = 1): void
{
    test()->carts->add(test()->customer, test()->sku, $quantity);

    $session = test()->checkout->openCart(test()->customer, []);
    test()->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);
}

/** @return array<string, mixed> */
function dashboardFor(User $user): array
{
    return test()->actingAs($user)->getJson('/api/v1/seller/dashboard')->assertOk()->json('data');
}

// --- the queue --------------------------------------------------------------------

it('counts an order the seller has not confirmed yet', function (): void {
    expect(dashboardFor($this->owner)['waiting']['unconfirmed_orders'])->toBe(0);

    buyFromSeller();

    // The number somebody opens this page for: a customer is waiting and does not know it.
    expect(dashboardFor($this->owner)['waiting']['unconfirmed_orders'])->toBe(1);
});

it('moves an order from unconfirmed to waiting for a parcel', function (): void {
    buyFromSeller();

    $sellerOrder = SellerOrder::query()->latest('created_at')->firstOrFail();

    app(OrderStatusService::class)
        ->advance($sellerOrder, SellerOrderStatus::Confirmed);

    $waiting = dashboardFor($this->owner)['waiting'];

    // Confirming does not finish the work; it moves it to the next person.
    expect($waiting['unconfirmed_orders'])->toBe(0)
        ->and($waiting['to_ship'])->toBe(1);
});

it('counts a listing whose stock is running out', function (): void {
    expect(dashboardFor($this->owner)['waiting']['low_stock'])->toBe(0);

    // Down to three: worth a look, and early enough to do something about.
    $this->stock->adjust($this->stock->itemFor($this->sku), -7, MovementType::Adjustment);

    expect(dashboardFor($this->owner)['waiting']['low_stock'])->toBe(1);
});

it('counts a listing that has gone dark separately from one running low', function (): void {
    $this->stock->adjust($this->stock->itemFor($this->sku), -10, MovementType::Adjustment);

    $data = dashboardFor($this->owner);

    /*
     * Not the same problem. Low stock is a reminder; nothing on the shelf is a listing that
     * is no longer selling, and folding them into one number would bury the second.
     */
    expect($data['waiting']['low_stock'])->toBe(0)
        ->and($data['catalogue']['out_of_stock'])->toBe(1);
});

// --- the money --------------------------------------------------------------------

it('reports what is theirs rather than what the customer paid', function (): void {
    buyFromSeller(2);

    $sales = dashboardFor($this->owner)['sales'];

    // A gross figure with the commission still in it is the number a seller plans around
    // and then does not receive.
    expect($sales['orders'])->toBe(1)
        ->and($sales['gross_minor'])->toBeGreaterThan(0)
        ->and($sales['commission_minor'])->toBeGreaterThan(0)
        ->and($sales['payable_minor'])->toBe($sales['gross_minor'] - $sales['commission_minor']);
});

it('takes the balances from the ledger projection', function (): void {
    buyFromSeller();

    $earnings = dashboardFor($this->owner)['earnings'];

    // Four states, because the money really is in four. A single "bakiye" is how a seller
    // reads a number they cannot yet have.
    expect($earnings)->toHaveKeys(['available_minor', 'pending_minor', 'in_settlement_minor', 'paid_minor'])
        ->and($earnings['pending_minor'])->toBeGreaterThan(0);
});

// --- isolation --------------------------------------------------------------------

it('never counts another seller work', function (): void {
    [$rival, $rivalOwner] = makeApprovedSeller('Rakip Pano', 'rakip-pano');
    $rivalOwner->forceFill(['email_verified_at' => now()])->save();

    makeProduct($rival, makeCategory('Masa', 'masa-pano', 'living_room'), [
        'name' => 'Rakip masası',
        'description' => 'Rakip testleri.',
        'price_minor' => 100_000,
        'stock_quantity' => 4,
    ]);

    buyFromSeller();

    // One seller's queue is not the other's, and the dashboard has no id to ask with.
    expect(dashboardFor($this->owner)['waiting']['unconfirmed_orders'])->toBe(1)
        ->and(dashboardFor($rivalOwner)['waiting']['unconfirmed_orders'])->toBe(0);

    expect(dashboardFor($rivalOwner)['sales']['orders'])->toBe(0);
});

it('answers a member of staff as well as the owner', function (): void {
    $staff = User::factory()->create();
    $staff->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/seller/team', ['email' => $staff->email, 'role' => 'seller-staff'])
        ->assertCreated();

    buyFromSeller();

    /*
     * A colleague has no application of their own — that belongs to the owner — so keying
     * this page off the application would show every member of staff an invitation to
     * start applying.
     */
    expect(dashboardFor($staff)['waiting']['unconfirmed_orders'])->toBe(1);
});

it('refuses an account with no seller behind it', function (): void {
    $outsider = User::factory()->create();
    $outsider->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($outsider)->getJson('/api/v1/seller/dashboard')->assertNotFound();
});

it('never caches a seller figures in a shared store', function (): void {
    // Somebody else's numbers arriving from a proxy would be worse than the page being
    // slow, and these are all per-seller.
    $this->actingAs($this->owner)
        ->getJson('/api/v1/seller/dashboard')
        ->assertHeader('Cache-Control', 'no-store, private');
});

<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Commerce\Models\Cart;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomGeometryVersion;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * From a room to a basket.
 *
 * The end of the module and the reason for it. A plan is a list of real products at real
 * sizes in a room they have been checked against; asking somebody to find each of them again
 * in the shop is asking them to do the work twice.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    [$this->seller] = makeApprovedSeller('Sepet Test A.Ş.', 'sepet-test');

    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";

    RoomGeometryVersion::query()->create([
        'room_id' => $this->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 4_850,
        'length_mm' => 5_200,
        'height_mm' => 2_720,
    ])->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();
});

/**
 * A buyable product with known dimensions.
 *
 * @return array{0: string, 1: string}
 */
function buyable(string $name, string $categorySlug, int $width, int $depth): array
{
    $category = Category::query()->firstWhere('slug', $categorySlug)
        ?? makeCategory(ucfirst($categorySlug), $categorySlug, 'living_room');

    $product = makeProduct(test()->seller, $category, [
        'name' => $name,
        'price_minor' => 100_000,
        'width_mm' => $width,
    ]);

    $sku = $product->skus->firstOrFail();

    ProductDimension::query()->updateOrCreate(
        ['sku_id' => $sku->getKey()],
        ['width_mm' => $width, 'height_mm' => 800, 'depth_mm' => $depth],
    );

    // The catalogue's own stock_quantity is the seller's figure; what a basket may take
    // comes from the inventory ledger, so the fixture puts some there.
    $ledger = app(InventoryLedger::class);
    $ledger->adjust($ledger->itemFor($sku), 5, MovementType::Receipt);

    return [$product->getKey(), $sku->getKey()];
}

/** @param  list<array{0: string, 1: string, 2: int, 3: int}>  $pieces */
function layoutOf(array $pieces): void
{
    test()->actingAs(test()->owner)->putJson(test()->url.'/layout', [
        'items' => array_map(static fn (array $piece): array => [
            'product_id' => $piece[0],
            'sku_id' => $piece[1],
            'position_x_mm' => $piece[2],
            'position_z_mm' => $piece[3],
        ], $pieces),
    ])->assertOk();
}

it('puts everything standing in the room into the basket', function (): void {
    [$sofaProduct, $sofaSku] = buyable('Üçlü kanepe', 'kanepe', 2_200, 900);
    [$tableProduct, $tableSku] = buyable('Orta sehpa', 'sehpa', 900, 900);

    layoutOf([
        [$sofaProduct, $sofaSku, 2_400, 800],
        [$tableProduct, $tableSku, 2_400, 2_600],
    ]);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/cart")
        ->assertOk();

    $cart = Cart::query()->where('user_id', $this->owner->getKey())->firstOrFail();

    expect($response->json('data.added'))->toBe(2)
        ->and($cart->items()->count())->toBe(2);
});

it('counts a pair of bedside tables as one line of two', function (): void {
    [$product, $sku] = buyable('Komodin', 'komodin', 450, 400);

    // The layout holds one row per piece standing in the room, which is the right shape for
    // a plan and the wrong shape for a basket.
    layoutOf([
        [$product, $sku, 1_000, 800],
        [$product, $sku, 3_800, 800],
    ]);

    $this->actingAs($this->owner)->postJson("{$this->url}/layout/cart")->assertOk();

    $cart = Cart::query()->where('user_id', $this->owner->getKey())->firstOrFail();

    expect($cart->items()->count())->toBe(1)
        ->and((int) $cart->items()->first()?->quantity)->toBe(2);
});

it('names what it could not add rather than quietly leaving it out', function (): void {
    [$sofaProduct, $sofaSku] = buyable('Üçlü kanepe', 'kanepe', 2_200, 900);
    [$goneProduct, $goneSku] = buyable('Kaldırılan konsol', 'konsol', 1_400, 420);

    layoutOf([
        [$sofaProduct, $sofaSku, 2_400, 800],
        [$goneProduct, $goneSku, 2_400, 4_600],
    ]);

    // Withdrawn after the plan was made, which is the ordinary case: a seller takes a listing
    // down while somebody is still arranging their room around it.
    ProductSku::query()->whereKey($goneSku)
        ->update(['status' => SkuStatus::Paused]);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/cart")
        ->assertOk();

    /*
     * A basket that quietly contains four of the five things somebody planned is a basket
     * they discover at the door.
     */
    expect($response->json('data.added'))->toBe(1)
        ->and($response->json('meta.refused'))->toHaveCount(1)
        ->and($response->json('meta.refused.0.name'))->toBe('Kaldırılan konsol');
});

it('refuses an empty room rather than an empty basket', function (): void {
    $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/cart")
        ->assertStatus(422);
});

it('keeps a stranger out of somebody elses room', function (): void {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson("{$this->url}/layout/cart")
        ->assertForbidden();
});

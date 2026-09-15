<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Models\RoomMedia;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Measuring a room, agreeing to the measurements, and putting furniture in it.
 *
 * The confirmation step is what these tests are mostly about. A photograph read by a model
 * gives numbers that are usually close and occasionally wrong by half a metre, and everything
 * downstream rests on them — whether the sofa fits, what the render is told the room looks
 * like, what the customer is invited to buy. So a measurement is proposed, shown, and used
 * only once somebody has said yes.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    [$this->seller] = makeApprovedSeller('Yerleşim API A.Ş.', 'yerlesim-api');

    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";
});

/**
 * A product with known dimensions, and its variant.
 *
 * @return array{0: Product, 1: ProductSku}
 */
function catalogue(string $name, string $categorySlug, int $widthMm, int $depthMm): array
{
    $category = Category::query()->firstWhere('slug', $categorySlug)
        ?? makeCategory(ucfirst($categorySlug), $categorySlug, 'living_room');

    $product = makeProduct(test()->seller, $category, [
        'name' => $name,
        'price_minor' => 100_000,
        'width_mm' => $widthMm,
    ]);

    $sku = $product->skus->firstOrFail();

    ProductDimension::query()->updateOrCreate(
        ['sku_id' => $sku->getKey()],
        ['width_mm' => $widthMm, 'height_mm' => 800, 'depth_mm' => $depthMm],
    );

    return [$product, $sku];
}

/** Records measurements and agrees to them, the way the confirm screen does. */
function confirmGeometry(int $width = 4_850, int $length = 5_200, int $height = 2_720): RoomGeometryVersion
{
    $version = RoomGeometryVersion::query()->create([
        'room_id' => test()->room->getKey(),
        'version' => ((int) RoomGeometryVersion::query()->where('room_id', test()->room->getKey())->max('version')) + 1,
        'source' => 'user',
        'width_mm' => $width,
        'length_mm' => $length,
        'height_mm' => $height,
    ]);

    $version->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();

    return $version;
}

// --- measurements ------------------------------------------------------------------

it('records measurements without treating them as agreed', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry", [
            'width_mm' => 4_850,
            'length_mm' => 5_200,
            'height_mm' => 2_720,
            'source' => 'ai',
            'confidence_bps' => 7_400,
        ])
        ->assertCreated();

    // Proposed, not adopted. Until somebody says yes it is a guess with a decimal point on it.
    expect($response->json('data.is_confirmed'))->toBeFalse()
        ->and($response->json('data.confidence_percent'))->toBe(74)
        ->and($this->room->fresh()->width_mm)->not->toBe(4_850);
});

it('refuses a room the size of a shoebox', function (): void {
    // 485 where 4850 was meant is one keystroke, and the difference between a room and a
    // corridor. It comes back as a sentence rather than a constraint violation.
    $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry", ['width_mm' => 485, 'length_mm' => 5_200, 'height_mm' => 2_720])
        ->assertStatus(422);
});

it('writes confirmed measurements back onto the room', function (): void {
    $created = $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry", ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720])
        ->json('data.id');

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry/{$created}/confirm")
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true);

    /*
     * The rest of the system reads a room's size from the room: the design brief, the render
     * prompt, the shopping list's idea of what will fit. Two places holding it is one place
     * holding a stale copy, and the stale one is always the place somebody forgot to look at.
     */
    expect($this->room->fresh()->width_mm)->toBe(4_850);
});

it('keeps exactly one confirmed set of measurements', function (): void {
    $first = confirmGeometry();

    $second = $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry", ['width_mm' => 5_000, 'length_mm' => 5_200, 'height_mm' => 2_720])
        ->json('data.id');

    $this->actingAs($this->owner)->postJson("{$this->url}/geometry/{$second}/confirm")->assertOk();

    // A partial unique index enforces this. The old one is stood down first rather than both
    // being written and one losing on an index nobody can explain to a customer.
    expect($first->fresh()->is_confirmed)->toBeFalse()
        ->and(RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->where('is_confirmed', true)->count())
        ->toBe(1);
});

it('will not confirm measurements belonging to another room', function (): void {
    $elsewhere = Project::factory()->ownedBy($this->owner)->withRoom()->create();

    $other = RoomGeometryVersion::query()->create([
        'room_id' => $elsewhere->rooms()->firstOrFail()->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 3_000,
        'length_mm' => 3_000,
        'height_mm' => 2_500,
    ]);

    // 404 rather than 403: an id from another room should not be confirmable as existing.
    $this->actingAs($this->owner)
        ->postJson("{$this->url}/geometry/{$other->getKey()}/confirm")
        ->assertNotFound();
});

// --- the layout --------------------------------------------------------------------

it('saves an arrangement and says what is wrong with it', function (): void {
    confirmGeometry();

    [$sofa, $sofaSku] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);
    [$sideboard, $sideboardSku] = catalogue('Konsol', 'konsol', 1_400, 400);

    $response = $this->actingAs($this->owner)
        ->putJson("{$this->url}/layout", [
            'items' => [
                [
                    'product_id' => $sofa->getKey(),
                    'sku_id' => $sofaSku->getKey(),
                    'position_x_mm' => 2_400,
                    'position_z_mm' => 2_600,
                ],
                [
                    'product_id' => $sideboard->getKey(),
                    'sku_id' => $sideboardSku->getKey(),
                    'position_x_mm' => 2_500,
                    'position_z_mm' => 2_650,
                ],
            ],
        ])
        ->assertOk();

    // Two pieces in the same place. The browser worked this out too, in the frame it
    // happened; this is the copy that decides.
    expect($response->json('data.has_collisions'))->toBeTrue()
        ->and(collect($response->json('data.items'))->pluck('collision_state')->all())
        ->toBe(['blocked', 'blocked']);
});

it('computes the collision state rather than believing the one it was sent', function (): void {
    confirmGeometry();

    [$sofa, $sku] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);

    $response = $this->actingAs($this->owner)
        ->putJson("{$this->url}/layout", [
            'items' => [[
                'product_id' => $sofa->getKey(),
                'sku_id' => $sku->getKey(),
                // Half of it is through the west wall, and the payload claims it is fine.
                'position_x_mm' => 300,
                'position_z_mm' => 2_600,
                'collision_state' => 'ok',
            ]],
        ])
        ->assertOk();

    // A state is a fact about a room, not an opinion of whoever is holding the mouse.
    expect($response->json('data.items.0.collision_state'))->toBe('blocked');
});

it('refuses a variant that belongs to a different product', function (): void {
    confirmGeometry();

    [$sofa] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);
    [, $otherSku] = catalogue('Konsol', 'konsol', 1_400, 400);

    /*
     * The pair is what decides the size of the box in the room. A layout holding one
     * product's id and another variant's is a plan that fits on screen and does not fit in
     * the flat.
     */
    $this->actingAs($this->owner)
        ->putJson("{$this->url}/layout", [
            'items' => [[
                'product_id' => $sofa->getKey(),
                'sku_id' => $otherSku->getKey(),
                'position_x_mm' => 2_400,
                'position_z_mm' => 2_600,
            ]],
        ])
        ->assertStatus(422);
});

it('will not take a layout before the measurements are agreed', function (): void {
    [$sofa, $sku] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);

    $this->actingAs($this->owner)
        ->putJson("{$this->url}/layout", [
            'items' => [[
                'product_id' => $sofa->getKey(),
                'sku_id' => $sku->getKey(),
                'position_x_mm' => 2_400,
                'position_z_mm' => 2_600,
            ]],
        ])
        ->assertStatus(422);
});

it('replaces the whole arrangement rather than adding to it', function (): void {
    confirmGeometry();

    [$sofa, $sku] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);

    $payload = ['items' => [[
        'product_id' => $sofa->getKey(),
        'sku_id' => $sku->getKey(),
        'position_x_mm' => 2_400,
        'position_z_mm' => 2_600,
    ]]];

    $this->actingAs($this->owner)->putJson("{$this->url}/layout", $payload)->assertOk();

    // The editor holds the arrangement and sends all of it. Saving twice is the same room,
    // not two sofas.
    $response = $this->actingAs($this->owner)->putJson("{$this->url}/layout", $payload)->assertOk();

    expect($response->json('data.items'))->toHaveCount(1);
});

it('hands the editor the room, its openings and its furniture in one request', function (): void {
    confirmGeometry();

    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'east',
        'offset_mm' => 400,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    [$sofa, $sku] = catalogue('Üçlü Kanepe', 'kanepe', 2_200, 900);

    $this->actingAs($this->owner)->putJson("{$this->url}/layout", ['items' => [[
        'product_id' => $sofa->getKey(),
        'sku_id' => $sku->getKey(),
        'position_x_mm' => 2_400,
        'position_z_mm' => 2_600,
    ]]])->assertOk();

    $response = $this->actingAs($this->owner)->getJson("{$this->url}/layout")->assertOk();

    // A room with no openings is a sealed box and a layout with no geometry has nowhere to
    // stand, so the editor cannot usefully open on a subset of this.
    expect($response->json('data.geometry.width_mm'))->toBe(4_850)
        ->and($response->json('data.openings'))->toHaveCount(1)
        ->and($response->json('data.layout.items.0.width_mm'))->toBe(2_200)
        ->and($response->json('data.layout.items.0.category'))->toBe('kanepe');
});

it('tells the editor what the floor is made of, in its own three words', function (): void {
    confirmGeometry();

    $media = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'disk' => 's3',
        'storage_path' => 'rooms/'.$this->room->getKey().'/oda.jpg',
        'original_name' => 'oda.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1_024,
        'checksum_sha256' => hash('sha256', 'oda'),
        'type' => 'photo',
    ]);

    // The model says "laminat parke"; the planner has boards, tiles and carpet.
    RoomAnalysis::query()->create([
        'room_id' => $this->room->getKey(),
        'media_id' => $media->getKey(),
        'payload' => [],
        'surfaces' => ['floor' => ['material' => 'laminat parke', 'change_allowed' => false]],
        'is_current' => true,
    ]);

    $response = $this->actingAs($this->owner)->getJson("{$this->url}/layout")->assertOk();

    expect($response->json('data.geometry.floor'))->toBe('wood');
});

it('keeps a stranger out of somebody elses plan', function (): void {
    confirmGeometry();

    $this->actingAs($this->stranger)->getJson("{$this->url}/layout")->assertForbidden();

    $this->actingAs($this->stranger)
        ->postJson("{$this->url}/geometry", ['width_mm' => 4_000, 'length_mm' => 4_000, 'height_mm' => 2_500])
        ->assertForbidden();
});

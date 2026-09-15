<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\LayoutGeometry;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Whether a piece of furniture is somewhere the room will actually take it.
 *
 * The browser answers this too, while somebody drags, because a warning that arrives after a
 * round trip is a warning nobody waits for. These tests are about the other copy — the one
 * that decides. A layout arrives over HTTP and nothing in it can be trusted: not the
 * coordinates, not the collision flags the client helpfully computed, not that the sofa is
 * inside the room at all.
 *
 * The room is the storyboard's: 4.85 m across, 5.20 m deep, with a door on the east wall.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->geometryService = app(LayoutGeometry::class);

    $project = Project::factory()->withRoom()->create();
    $this->room = $project->rooms()->firstOrFail();

    $this->geometry = RoomGeometryVersion::query()->create([
        'room_id' => $this->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 4_850,
        'length_mm' => 5_200,
        'height_mm' => 2_720,
    ]);

    $this->geometry->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();

    $this->layout = DesignLayout::query()->create([
        'room_id' => $this->room->getKey(),
        'geometry_version_id' => $this->geometry->getKey(),
        'version' => 1,
        'source' => 'user',
    ]);

    [$this->seller] = makeApprovedSeller('Yerleşim Test A.Ş.', 'yerlesim-test');
});

/**
 * A product of a given size in a given category, and one of it placed in the room.
 *
 * Written as a helper because every assertion below needs a piece of furniture with known
 * dimensions, and a test that spends fifteen lines creating a sofa before it can say
 * anything about collisions is a test nobody reads.
 */
function place(
    string $name,
    string $categorySlug,
    int $widthMm,
    int $depthMm,
    int $x,
    int $z,
    int $rotation = 0,
    int $y = 0,
): DesignLayoutItem {
    // Reused rather than created: two sofas in one test are two products in one category,
    // and a second insert on the same slug is a unique violation rather than a second sofa.
    $category = Category::query()->firstWhere('slug', $categorySlug)
        ?? makeCategory(ucfirst($categorySlug), $categorySlug, 'living_room');

    $product = makeProduct(
        test()->seller,
        $category,
        ['name' => $name, 'price_minor' => 100_000, 'width_mm' => $widthMm],
    );

    $sku = $product->skus->firstOrFail();

    // makeProduct fixes depth at 900; these tests care about it, so it is set explicitly.
    ProductDimension::query()->updateOrCreate(
        ['sku_id' => $sku->getKey()],
        ['width_mm' => $widthMm, 'height_mm' => 800, 'depth_mm' => $depthMm],
    );

    return DesignLayoutItem::query()->create([
        'layout_id' => test()->layout->getKey(),
        'product_id' => $product->getKey(),
        'sku_id' => $sku->getKey(),
        'position_x_mm' => $x,
        'position_y_mm' => $y,
        'position_z_mm' => $z,
        'rotation_y_deg' => $rotation,
    ]);
}

it('accepts a sofa standing on its own in the middle of the room', function (): void {
    $sofa = place('Kanepe', 'kanepe', 2_200, 900, 2_400, 2_600);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    expect($states[$sofa->id])->toBe('ok');
});

it('refuses two pieces standing in the same place', function (): void {
    $sofa = place('Kanepe', 'kanepe', 2_200, 900, 2_400, 2_600);
    $sideboard = place('Konsol', 'konsol', 1_400, 400, 2_500, 2_650);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    // Not a warning. A sideboard cannot be delivered into the space a sofa occupies, and
    // offering to proceed anyway would be offering something that cannot happen.
    expect($states[$sofa->id])->toBe('blocked')
        ->and($states[$sideboard->id])->toBe('blocked');
});

it('lets a coffee table stand on a rug', function (): void {
    $rug = place('Halı', 'hali', 2_400, 1_700, 2_400, 2_600);
    $table = place('Sehpa', 'sehpa', 900, 900, 2_400, 2_600);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    /*
     * The whole reason the exemption exists. Without it every layout containing a carpet
     * reports four collisions, and a customer who sees four warnings they know are wrong
     * stops reading the fifth — which will not be.
     */
    expect($states[$rug->id])->toBe('ok')
        ->and($states[$table->id])->toBe('ok');
});

it('refuses a piece that sticks out through a wall', function (): void {
    // Centred 300 mm from the west wall with a 2200 mm sofa: half of it is outside the room.
    $sofa = place('Kanepe', 'kanepe', 2_200, 900, 300, 2_600);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    expect($states[$sofa->id])->toBe('blocked');
});

it('refuses a wardrobe standing in the doorway', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'east',
        'offset_mm' => 400,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    // Against the east wall, right where the door opens.
    $wardrobe = place('Gardırop', 'gardirop', 1_200, 600, 4_500, 850, 90);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    // A door that opens into a wardrobe has stopped being a door.
    expect($states[$wardrobe->id])->toBe('blocked');
});

it('warns rather than refuses when something stands in front of a window', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'window',
        'wall' => 'north',
        'offset_mm' => 720,
        'width_mm' => 1_800,
        'height_mm' => 1_600,
        'sill_height_mm' => 900,
    ]);

    // Clear of the wall — 900 deep centred at 500 leaves its back 50 mm inside the room —
    // but well within the 300 mm the window is owed.
    $sofa = place('Kanepe', 'kanepe', 2_200, 900, 1_600, 500);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    /*
     * A sofa with its back to a window is an ordinary arrangement somebody may well want,
     * so this is something to be told rather than something to be stopped from doing. A
     * blocked doorway is not a taste question; a covered window is.
     */
    expect($states[$sofa->id])->toBe('warning');
});

it('treats a balcony door like a door', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'balcony_door',
        'wall' => 'south',
        'offset_mm' => 1_000,
        'width_mm' => 1_600,
        'height_mm' => 2_200,
    ]);

    $sideboard = place('Konsol', 'konsol', 1_400, 450, 1_800, 4_900);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    // It was not, for one afternoon, and the layout engine let a sideboard stand across the
    // only way onto the balcony.
    expect($states[$sideboard->id])->toBe('blocked');
});

it('lets a picture hang above a sideboard', function (): void {
    $sideboard = place('Konsol', 'konsol', 1_400, 450, 2_400, 300);
    $picture = place('Tablo', 'tablo', 900, 50, 2_400, 250, 0, 1_500);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    // They share a floor plan and not a cubic centimetre of space. Anything above the floor
    // is out of the way of everything on it.
    expect($states[$sideboard->id])->toBe('ok')
        ->and($states[$picture->id])->toBe('ok');
});

it('turns a footprint when the piece is turned', function (): void {
    /*
     * A 2200 × 900 sofa turned ninety degrees occupies 900 × 2200, and this is the arithmetic
     * that decides whether it fits beside a door. Getting it wrong is invisible on screen —
     * the model rotates correctly either way — and shows up as a collision check that passes
     * for a position the room will not take.
     */
    $upright = place('Kanepe', 'kanepe', 2_200, 900, 2_400, 2_600);
    $turned = place('Kanepe Y', 'kanepe', 2_200, 900, 2_400, 2_600, 90);

    $service = $this->geometryService;

    expect($service->rectangleOf($upright))->toBe(['x1' => 1_300, 'z1' => 2_150, 'x2' => 3_500, 'z2' => 3_050])
        ->and($service->rectangleOf($turned))->toBe(['x1' => 1_950, 'z1' => 1_500, 'x2' => 2_850, 'z2' => 3_700]);
});

it('lets a curtain hang behind the sofa and a picture over the door', function (): void {
    // On the wall is not on the floor. Mirrored in the browser with the same numbers.
    $sofa = place('Kanepe', 'kanepe', 2_200, 900, 2_400, 500);
    $curtain = place('Perde', 'perde', 2_000, 20, 2_400, 10);
    $picture = place('Tablo', 'tablo', 600, 30, 4_250, 15, 0, 1_500);

    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'north',
        'offset_mm' => 3_800,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    expect($states[$curtain->id])->toBe('ok')
        ->and($states[$sofa->id])->toBe('ok')
        ->and($states[$picture->id])->toBe('ok');
});

it('boxes a piece turned off a right angle by the box it actually fits in', function (): void {
    // At 45° a 2200 × 900 rectangle fits in a 2192 × 2192 box. That box is what the wall
    // arithmetic uses; whether it touches anything is decided by its outline, below.
    $diagonal = place('Kanepe', 'kanepe', 2_200, 900, 2_400, 2_600, 45);

    expect($this->geometryService->rectangleOf($diagonal))
        ->toBe(['x1' => 1_304, 'z1' => 1_504, 'x2' => 3_496, 'z2' => 3_696]);
});

it('lets a turned sofa and a table share the box round them but not the floor', function (): void {
    /*
     * A sofa on the diagonal and a table tucked into the corner its bounding box covers. The
     * box says they collide; the outlines say they do not, and the outlines are right — this
     * is the arrangement people make on purpose. Mirrored in the browser with the same numbers.
     */
    place('Kanepe', 'kanepe', 2_200, 900, 2_400, 2_600, 45);
    $clear = place('Sehpa', 'sehpa', 500, 500, 3_300, 1_700);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    expect($states[$clear->id])->toBe('ok');

    $across = place('Sehpa 2', 'sehpa', 500, 500, 2_400, 3_200);

    $states = $this->geometryService->evaluate($this->layout->fresh());

    expect($states[$across->id])->toBe('blocked');
});

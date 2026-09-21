<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\LayoutPlanImage;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The plan, drawn by the server, for a renderer that has nothing better to follow.
 *
 * Asserted as a picture rather than as pixels: what matters is that a PNG comes out, that it
 * has the room's proportions rather than a square, and that it refuses to draw a room with no
 * measurements. What it looks like is a judgement, and a test that pinned every pixel would
 * fail on a font change and tell nobody anything.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    $this->plans = app(LayoutPlanImage::class);
});

/**
 * A room of a given size, with an arrangement in it.
 *
 * @return array{0: RoomGeometryVersion, 1: DesignLayout}
 */
function roomToDraw(int $widthMm, int $lengthMm, int $items = 1): array
{
    $geometry = RoomGeometryVersion::query()->create([
        'room_id' => test()->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => $widthMm,
        'length_mm' => $lengthMm,
        'height_mm' => 2_600,
    ]);

    $layout = DesignLayout::query()->create([
        'room_id' => test()->room->getKey(),
        'geometry_version_id' => $geometry->getKey(),
        'version' => 1,
        'source' => 'ai',
        'status' => 'draft',
        'created_by' => test()->owner->getKey(),
    ]);

    [$seller] = makeApprovedSeller('Kroki A.Ş.', 'kroki');

    $category = Category::query()->firstWhere('slug', 'kanepe')
        ?? makeCategory('Kanepe', 'kanepe', 'living_room');

    for ($index = 0; $index < $items; $index++) {
        $product = makeProduct($seller, $category, ['name' => 'Kanepe '.$index, 'price_minor' => 100_000, 'width_mm' => 2_200]);
        $sku = $product->skus->firstOrFail();

        ProductDimension::query()->updateOrCreate(
            ['sku_id' => $sku->getKey()],
            ['width_mm' => 2_200, 'depth_mm' => 900, 'height_mm' => 800],
        );

        DesignLayoutItem::query()->create([
            'layout_id' => $layout->getKey(),
            'product_id' => $product->getKey(),
            'sku_id' => $sku->getKey(),
            'position_x_mm' => 1_200,
            'position_z_mm' => 500 + $index * 1_000,
            'rotation_y_deg' => 0,
        ]);
    }

    return [$geometry, $layout];
}

it('draws a PNG of the room at its own proportions', function (): void {
    [$geometry, $layout] = roomToDraw(3_000, 6_000);

    $bytes = $this->plans->draw($geometry, $layout, []);

    expect($bytes)->toBeString()->not->toBeEmpty();

    $size = getimagesizefromstring((string) $bytes);

    expect($size)->not->toBeFalse()
        ->and($size[2])->toBe(IMAGETYPE_PNG)
        /*
         * Twice as long as it is wide, like the room. A plan squeezed into a square is a
         * plan of a different room, and everything measured off it afterwards is wrong by
         * the same factor.
         */
        ->and(($size[1] - 96) / ($size[0] - 96))->toEqualWithDelta(2.0, 0.02);
});

it('marks the doors and the windows on the walls they are on', function (): void {
    [$geometry, $layout] = roomToDraw(4_000, 5_000);

    $door = new RoomConstraint;
    $door->forceFill(['type' => 'door', 'wall' => 'north', 'offset_mm' => 500, 'width_mm' => 900]);

    $window = new RoomConstraint;
    $window->forceFill(['type' => 'window', 'wall' => 'east', 'offset_mm' => 1_000, 'width_mm' => 2_000]);

    $plain = $this->plans->draw($geometry, $layout, []);
    $marked = $this->plans->draw($geometry, $layout, [$door, $window]);

    /*
     * Compared against the same room without them rather than by reading pixels: two doors
     * on a drawing are two coloured runs of wall, and the drawing is bigger for it. It is a
     * coarse assertion and it catches the failure that matters — openings silently missing.
     */
    expect(strlen((string) $marked))->toBeGreaterThan(strlen((string) $plain));
});

it('refuses to draw a room nobody has measured', function (): void {
    [, $layout] = roomToDraw(4_000, 5_000);

    // Unsaved, because the column will not hold a nought — which is the right rule and
    // also why this can only ever arrive here as an object somebody built wrong.
    $nothing = new RoomGeometryVersion;
    $nothing->forceFill(['width_mm' => 0, 'length_mm' => 5_000, 'height_mm' => 2_600]);

    // A plan of a room with no width is a rectangle of nothing, and a renderer handed one
    // would follow it. Saying there is no drawing is the honest answer.
    expect($this->plans->draw($nothing, $layout, []))->toBeNull();
});

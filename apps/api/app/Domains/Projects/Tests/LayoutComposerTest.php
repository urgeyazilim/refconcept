<?php

declare(strict_types=1);

use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\LayoutComposer;

/**
 * Turning a design's words into positions.
 *
 * The plan a model writes says "a sofa on the north wall, a rug under the seating group, a
 * picture above the sideboard". Those are the right words for it to produce; the arithmetic
 * is ours, and it is the part that decides whether the room works. These tests are about the
 * arithmetic — nothing here touches the database, because none of it should depend on one.
 *
 * The room is the storyboard's: 4.85 m across, 5.20 m deep, door on the east wall.
 */
beforeEach(function (): void {
    $this->composer = new LayoutComposer;

    $this->geometry = new RoomGeometryVersion;
    $this->geometry->forceFill(['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function opening(string $type, string $wall, int $offset, int $width, array $attributes = []): RoomConstraint
{
    $constraint = new RoomConstraint;

    $constraint->forceFill([
        'type' => ConstraintType::from($type),
        'wall' => $wall,
        'offset_mm' => $offset,
        'width_mm' => $width,
        ...$attributes,
    ]);

    return $constraint;
}

/**
 * @return array{product_id: string, sku_id: string, category: string, width_mm: int, depth_mm: int, wall: string|null}
 */
function piece(string $category, int $width, int $depth, ?string $wall = null): array
{
    return [
        'product_id' => "p-{$category}",
        'sku_id' => "s-{$category}",
        'category' => $category,
        'width_mm' => $width,
        'depth_mm' => $depth,
        'wall' => $wall,
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>|null
 */
function find(array $items, string $category): ?array
{
    foreach ($items as $item) {
        if ($item['category'] === $category) {
            return $item;
        }
    }

    return null;
}

it('stands a wardrobe against a wall rather than in the middle of the floor', function (): void {
    $result = $this->composer->compose($this->geometry, [], [piece('gardirop', 1_200, 600, 'north')]);

    $wardrobe = find($result['items'], 'gardirop');

    // Against the north wall: 60 mm of clearance plus half its depth.
    expect($wardrobe['position_z_mm'])->toBe(360)
        ->and($wardrobe['rotation_y_deg'])->toBe(0);
});

it('leaves the doorway alone', function (): void {
    $door = opening('door', 'east', 400, 900, ['height_mm' => 2_100]);

    $result = $this->composer->compose($this->geometry, [$door], [piece('kitaplik', 1_600, 350, 'east')]);

    $bookcase = find($result['items'], 'kitaplik');

    /*
     * The door runs from 400 to 1300 along the east wall and is owed 300 mm either side, so
     * the first place a 1600 mm bookcase fits starts at 1600. Anything closer is a door that
     * cannot open, which is not a layout decision — it is a room that does not work.
     */
    expect($bookcase['position_z_mm'])->toBeGreaterThanOrEqual(1_600);
});

it('floats the seating off the wall when the room can afford it', function (): void {
    $result = $this->composer->compose($this->geometry, [], [piece('kanepe', 2_200, 900, 'north')]);

    $sofa = find($result['items'], 'kanepe');

    // 350 mm of air plus half the depth. Sofas in a row against the walls of a large room is
    // a waiting area; the plan says so in words and this is the arithmetic of it.
    expect($sofa['position_z_mm'])->toBe(800);
});

it('puts the seating against the wall in a room that cannot spare the space', function (): void {
    $small = new RoomGeometryVersion;
    $small->forceFill(['width_mm' => 3_000, 'length_mm' => 3_400, 'height_mm' => 2_600]);

    $result = $this->composer->compose($small, [], [piece('kanepe', 2_200, 900, 'north')]);

    $sofa = find($result['items'], 'kanepe');

    // In a small room the extra 350 mm is the walkway, and a customer who cannot get past
    // their own sofa does not care that it was a composition decision.
    expect($sofa['position_z_mm'])->toBe(510);
});

it('puts the coffee table in front of the sofa rather than against a wall', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 2_200, 900, 'north'),
        piece('sehpa', 900, 900, null),
    ]);

    $sofa = find($result['items'], 'kanepe');
    $table = find($result['items'], 'sehpa');

    // In front means towards the middle of the room, taken from the sofa's own rotation:
    // 420 mm of gap plus half of each depth.
    expect($table['position_x_mm'])->toBe($sofa['position_x_mm'])
        ->and($table['position_z_mm'])->toBe($sofa['position_z_mm'] + 420 + 450 + 450);
});

it('lays the rug down first, in the middle', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('sehpa', 900, 900, null),
        piece('hali', 2_400, 1_700, null),
    ]);

    $rug = find($result['items'], 'hali');

    /*
     * First in the list of placements regardless of the order it arrived in. Everything else
     * stands on or beside it, and a rug placed last is a rug squeezed between things.
     */
    expect($result['items'][0]['category'])->toBe('hali')
        ->and($rug['position_x_mm'])->toBe(2_425)
        ->and($rug['position_z_mm'])->toBe(2_600);
});

it('hangs a picture above the widest piece on its wall, off the floor', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('konsol', 1_400, 420, 'south'),
        piece('tablo', 900, 50, 'south'),
    ]);

    $sideboard = find($result['items'], 'konsol');
    $picture = find($result['items'], 'tablo');

    // Centred over what is underneath it, and 1.5 m up — which is also what keeps it out of
    // the sideboard's way, because the collision rules exempt anything above the floor.
    expect($picture['position_x_mm'])->toBe($sideboard['position_x_mm'])
        ->and($picture['position_y_mm'])->toBe(1_500);
});

it('says what it could not place instead of finding somewhere for it', function (): void {
    $narrow = new RoomGeometryVersion;
    $narrow->forceFill(['width_mm' => 2_000, 'length_mm' => 2_000, 'height_mm' => 2_500]);

    $result = $this->composer->compose($narrow, [], [
        piece('gardirop', 1_600, 600, 'north'),
        piece('kitaplik', 1_800, 350, 'north'),
        piece('vitrin', 1_800, 400, 'north'),
    ]);

    /*
     * A layout that quietly puts a wardrobe in the middle of the floor is worse than one that
     * says it did not fit: the customer can see a missing piece and choose a smaller one, and
     * cannot see a piece that is nowhere a delivery could put it.
     */
    expect($result['unplaced'])->not->toBeEmpty()
        ->and(count($result['items']) + count($result['unplaced']))->toBe(3);
});

it('fills a wall from one end rather than leaving gaps nothing fits in', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('konsol', 1_400, 420, 'north'),
        piece('kitaplik', 1_000, 350, 'north'),
    ]);

    $sideboard = find($result['items'], 'konsol');
    $bookcase = find($result['items'], 'kitaplik');

    // The wider one takes the corner, the next follows it. Two centred pieces would leave an
    // unusable gap at each end and a walkway down the middle of a wall.
    expect($sideboard['position_x_mm'])->toBe(850)
        ->and($bookcase['position_x_mm'])->toBe(2_050);
});

it('tries another wall before giving up', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('gardirop', 2_400, 600, 'north'),
        piece('kitaplik', 2_400, 350, 'north'),
    ]);

    $bookcase = find($result['items'], 'kitaplik');

    /*
     * Both asked for the north wall and only one fits along it. A wardrobe against the wrong
     * wall is a wardrobe the customer drags to the right one in a second; a wardrobe that was
     * never placed is a product they have to find in the catalogue again.
     */
    expect($bookcase)->not->toBeNull()
        ->and($bookcase['rotation_y_deg'])->not->toBe(0);
});

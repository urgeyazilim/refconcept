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
 * A piece as the matcher hands it over. Height is optional there, so it is optional here.
 *
 * @return array{product_id: string, sku_id: string, category: string, width_mm: int, depth_mm: int, height_mm: int|null, wall: string|null}
 */
function piece(string $category, int $width, int $depth, ?string $wall = null, ?int $height = null): array
{
    return [
        'product_id' => "p-{$category}",
        'sku_id' => "s-{$category}",
        'category' => $category,
        'width_mm' => $width,
        'depth_mm' => $depth,
        'height_mm' => $height,
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
    $small->forceFill(['width_mm' => 3_400, 'length_mm' => 3_000, 'height_mm' => 2_600]);

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

it('lays the rug in the middle when there is nothing to lay it under', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('hali', 2_400, 1_700, null),
    ]);

    $rug = find($result['items'], 'hali');

    expect($rug['position_x_mm'])->toBe(2_425)
        ->and($rug['position_z_mm'])->toBe(2_600);
});

it('halı kuralı: lays the rug under the front legs of the seating', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('hali', 2_400, 1_700, null),
        piece('kanepe', 2_200, 900, 'north'),
    ]);

    $sofa = find($result['items'], 'kanepe');
    $rug = find($result['items'], 'hali');

    /*
     * Centred on the sofa, and reaching 200 mm under its front edge: the front legs stand on
     * the rug, the back ones need not. A small rug floating in the middle of the floor with
     * the sofa behind it is the mistake this rule exists for.
     */
    $sofaFront = $sofa['position_z_mm'] + 450;
    $rugBack = $rug['position_z_mm'] - 850;

    expect($rug['position_x_mm'])->toBe($sofa['position_x_mm'])
        ->and($sofaFront - $rugBack)->toBe(200);
});

it('odak: seats the sofa across from the widest window, facing it', function (): void {
    $result = $this->composer->compose($this->geometry, [
        opening('window', 'south', 1_500, 1_800),
    ], [piece('kanepe', 2_200, 900, null)]);

    $sofa = find($result['items'], 'kanepe');

    // No wall was asked for, so the sofa goes to the north wall and looks south at the
    // window, rather than to whichever wall happened to have the most room.
    expect($sofa['rotation_y_deg'])->toBe(0)
        ->and($sofa['position_z_mm'])->toBeLessThan(1_000);
});

it('odak: the television is the focal point when there is one, and it faces the seating', function (): void {
    $result = $this->composer->compose($this->geometry, [
        opening('window', 'west', 200, 1_000),
    ], [
        piece('tv-unitesi', 1_600, 450, null),
        piece('kanepe', 2_200, 900, null),
    ]);

    $television = find($result['items'], 'tv-unitesi');
    $sofa = find($result['items'], 'kanepe');

    /*
     * The television goes across from the window so nobody watches it against the light —
     * the east wall — and the sofa goes across from the television, on the west wall under the
     * window, looking east. Simetri: both are centred on their walls, so they share a line.
     */
    expect($television['rotation_y_deg'])->toBe(90)
        ->and($sofa['rotation_y_deg'])->toBe(270)
        ->and($sofa['position_z_mm'])->toBe($television['position_z_mm']);
});

it('dolaşım: keeps the floor in front of the door empty, round the corner too', function (): void {
    // A door in the north-west corner, opening into the room.
    $result = $this->composer->compose($this->geometry, [
        opening('door', 'north', 100, 900),
    ], [
        piece('gardirop', 1_200, 600, 'west'),
    ]);

    $wardrobe = find($result['items'], 'gardirop');

    // The wardrobe asked for the west wall and gets it — but not the first 900 mm of it,
    // which is where somebody coming through the door is standing.
    expect($wardrobe['position_z_mm'] - 600)->toBeGreaterThanOrEqual(900);
});

it('dolaşım: does not float the sofa when what faces it leaves no room to walk past', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kitaplik', 2_000, 2_100, 'south'),
        piece('kanepe', 2_200, 900, 'north'),
    ]);

    $sofa = find($result['items'], 'kanepe');

    /*
     * 5200 deep, less a 2100 mm bookcase and its 60 mm of clearance, is 3040. The sofa, the
     * gap to the coffee table, the table and a passage past the group come to 2970, which
     * leaves 70 mm — not the 350 a float is worth having. Fifteen centimetres off a wall is
     * not an island, it is a gap nobody can reach into, so the sofa goes to the wall.
     */
    expect($sofa['position_z_mm'])->toBe(510);
});

it('dolaşım: floats the sofa when the passage past the group survives it', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kitaplik', 2_000, 1_800, 'south'),
        piece('kanepe', 2_200, 900, 'north'),
    ]);

    $sofa = find($result['items'], 'kanepe');

    /*
     * The same room with a 300 mm shallower bookcase: 3340 of clear depth against 2970 of
     * need, so the float fits with 370 to spare and the sofa stands off the wall.
     *
     * This used to go to the wall, because the rule was a single number — float in a room
     * with 3.8 m of clear depth — and 3340 is under it. The product owner's own living room
     * is 4 m across with a television unit facing the sofa, which comes to 3.54 m, and their
     * sofa went flat against the window wall in a room that could afford to pull it out.
     */
    expect($sofa['position_z_mm'])->toBe(800);
});

it('ölçek: stops when standing furniture would cover more than 40 % of the floor', function (): void {
    $narrow = new RoomGeometryVersion;
    $narrow->forceFill(['width_mm' => 3_000, 'length_mm' => 3_000, 'height_mm' => 2_500]);

    // 9 m² of floor; 40 % is 3.6 m². Two 1.4 m² pieces fit, the third would not.
    $result = $this->composer->compose($narrow, [], [
        piece('gardirop', 2_000, 700, 'north'),
        piece('kitaplik', 2_000, 700, 'south'),
        piece('konsol', 2_000, 700, 'east'),
    ]);

    expect($result['items'])->toHaveCount(2)
        ->and($result['unplaced'])->toHaveCount(1)
        ->and($result['unplaced'][0]['reason'])->toBe('scale');
});

it('ölçek: refuses a sofa longer than two thirds of its wall', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 3_400, 900, 'north'),
    ]);

    // 3400 on a 4850 wall is 70 %. The wall becomes a sofa, and the customer is told to pick
    // a shorter one rather than handed a room with no way round it.
    expect($result['items'])->toBeEmpty()
        ->and($result['unplaced'][0]['reason'])->toBe('scale');
});

it('simetri ve çift: stands the bedside tables either side of the bed', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('yatak', 1_800, 2_100, 'north'),
        piece('komodin', 500, 450, null),
        piece('komodin', 500, 450, null),
    ]);

    $bed = find($result['items'], 'yatak');
    $tables = array_values(array_filter($result['items'], static fn (array $item): bool => $item['category'] === 'komodin'));

    // The bed takes the middle of its wall so there is a side for each table; one goes each
    // side, 80 mm off the bed, backs to the same wall as the headboard.
    expect($bed['position_x_mm'])->toBe(2_425)
        ->and($tables)->toHaveCount(2)
        ->and($tables[0]['position_x_mm'])->toBe($bed['position_x_mm'] + 900 + 80 + 250)
        ->and($tables[1]['position_x_mm'])->toBe($bed['position_x_mm'] - 900 - 80 - 250)
        ->and($tables[0]['position_z_mm'] - 225)->toBe($bed['position_z_mm'] - 1_050);
});

it('aydınlatma: stands the floor lamp at the elbow of the sofa', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 2_200, 900, 'north'),
        piece('lambader', 400, 400, null),
    ]);

    $sofa = find($result['items'], 'kanepe');
    $lamp = find($result['items'], 'lambader');

    expect(abs($lamp['position_x_mm'] - $sofa['position_x_mm']))->toBe(1_100 + 80 + 200)
        ->and($lamp['position_z_mm'] - 200)->toBe($sofa['position_z_mm'] - 450);
});

it('yükseklik: hangs a sconce at 1.7 m and a curtain on the window', function (): void {
    $result = $this->composer->compose($this->geometry, [
        opening('window', 'south', 1_500, 1_800),
    ], [
        piece('duvar-aydinlatma', 200, 100, 'east'),
        piece('perde', 2_200, 60, null),
    ]);

    $sconce = find($result['items'], 'duvar-aydinlatma');
    $curtain = find($result['items'], 'perde');

    expect($sconce['position_y_mm'])->toBe(1_700)
        // Centred on the glass — 1500 + 900 — and hanging on the south wall, so the plate,
        // the scene and the render all put it where the window is.
        ->and($curtain['position_x_mm'])->toBe(2_400)
        ->and($curtain['position_z_mm'])->toBeGreaterThan(5_100)
        ->and($curtain['rotation_y_deg'])->toBe(180);
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

/*
 * --- windows -------------------------------------------------------------
 *
 * All three of these come from one real room: 4 x 5.5 m, a 2.5 m window with an 850 mm sill
 * taking the middle of the west wall, and a design asking for a 2.2 m sofa against it. The
 * composer refused the sofa with "no room" because the window blocked its wall the way a
 * doorway does, and then hung the rug and the coffee table on the armchair.
 */

it('pencere: stands a sofa under the window instead of refusing the wall', function (): void {
    $window = opening('window', 'west', 1_400, 2_500, ['height_mm' => 1_400, 'sill_height_mm' => 850]);

    $result = $this->composer->compose($this->geometry, [$window], [
        piece('kanepe', 2_200, 950, 'west', 780),
    ]);

    $sofa = find($result['items'], 'kanepe');

    // 78 cm of sofa under an 85 cm sill covers no glass, so the whole wall is still a wall.
    // The two 1.2 m ends the window used to leave fit nothing anybody sits on.
    expect($result['unplaced'])->toBeEmpty()
        ->and($sofa['position_z_mm'])->toBeGreaterThan(1_400)
        ->and($sofa['position_z_mm'])->toBeLessThan(3_900);
});

it('pencere: will not stand a wardrobe across the glass', function (): void {
    $window = opening('window', 'west', 1_400, 2_500, ['height_mm' => 1_400, 'sill_height_mm' => 850]);

    $result = $this->composer->compose($this->geometry, [$window], [
        piece('gardirop', 1_000, 600, 'west', 2_000),
    ]);

    $wardrobe = find($result['items'], 'gardirop');

    // It still gets its wall — just the end of it, where there is no window to board up.
    expect($wardrobe['position_z_mm'] + 500)->toBeLessThanOrEqual(1_400);
});

it('pencere: treats an unmeasured piece as tall', function (): void {
    $window = opening('window', 'west', 1_400, 2_500, ['height_mm' => 1_400, 'sill_height_mm' => 850]);

    $result = $this->composer->compose($this->geometry, [$window], [
        piece('kitaplik', 1_000, 350, 'west'),
    ]);

    $bookcase = find($result['items'], 'kitaplik');

    // Nobody measured it, so it might be two metres of shelving. A guess that boards up a
    // window is worse than a guess that uses the end of the wall.
    expect($bookcase['position_z_mm'] + 500)->toBeLessThanOrEqual(1_400);
});

it('grup: the rug and the table belong to the sofa, not to the last chair placed', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 2_200, 900, 'north', 780),
        piece('koltuk', 780, 820, 'east', 820),
        piece('sehpa', 900, 900),
        piece('hali', 2_400, 1_700),
    ]);

    $sofa = find($result['items'], 'kanepe');
    $chair = find($result['items'], 'koltuk');
    $table = find($result['items'], 'sehpa');
    $rug = find($result['items'], 'hali');

    /*
     * Seating is placed widest first, so the armchair is the last seat standing and used to
     * win. A 90 cm table in front of a 78 cm chair against the far wall, with a 2.4 m rug
     * under it, is a corner of clutter and a bare floor in front of the sofa.
     */
    expect($chair['position_x_mm'])->not->toBe($sofa['position_x_mm'])
        ->and($table['position_x_mm'])->toBe($sofa['position_x_mm'])
        ->and($rug['position_x_mm'])->toBe($sofa['position_x_mm']);
});

/*
 * --- the seating group ----------------------------------------------------
 *
 * Everything here comes from one real plan. It said, in as many words, "oturma grubu,
 * duvarlara yapıştırılmak yerine odanın merkezinde bir 'ada' olarak tasarlanmıştır", and of
 * the armchair: "oturma grubunun kuzey kanadında, kanepeye dik, diğer koltuğa bakacak
 * şekilde". The composer read the word "north" beside it and put the chair against the north
 * wall of the room, two and a half metres from the sofa it was meant to be talking to.
 */

it('oturma grubu: the armchairs take a wing each and look across the table at each other', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 2_200, 900, 'north', 780),
        piece('koltuk', 780, 820, 'north', 820),
        piece('koltuk', 780, 820, 'north', 820),
        piece('sehpa', 900, 900),
    ]);

    $sofa = find($result['items'], 'kanepe');
    $table = find($result['items'], 'sehpa');

    $chairs = array_values(array_filter(
        $result['items'],
        static fn (array $item): bool => $item['category'] === 'koltuk',
    ));

    usort($chairs, static fn (array $a, array $b): int => $a['position_x_mm'] <=> $b['position_x_mm']);

    expect($chairs)->toHaveCount(2)
        // Level with the table, not against the wall the plan named: a wall on a secondary
        // seat is a wing of the group and not a wall of the room.
        ->and($chairs[0]['position_z_mm'])->toBe($table['position_z_mm'])
        ->and($chairs[1]['position_z_mm'])->toBe($table['position_z_mm'])
        // One each side of the table, which is itself in front of the sofa.
        ->and($chairs[0]['position_x_mm'])->toBeLessThan($table['position_x_mm'])
        ->and($chairs[1]['position_x_mm'])->toBeGreaterThan($table['position_x_mm'])
        // Turned a quarter from the sofa, facing each other across the group.
        ->and($chairs[0]['rotation_y_deg'])->toBe(270)
        ->and($chairs[1]['rotation_y_deg'])->toBe(90)
        // And well clear of the sofa, which is still against its own wall.
        ->and($chairs[0]['position_z_mm'])->toBeGreaterThan($sofa['position_z_mm']);
});

it('oturma grubu: a second sofa is a second group, so it goes to a wall', function (): void {
    $result = $this->composer->compose($this->geometry, [], [
        piece('kanepe', 2_200, 900, 'north', 780),
        piece('kanepe', 2_200, 900, 'south', 780),
    ]);

    $sofas = array_values(array_filter(
        $result['items'],
        static fn (array $item): bool => $item['category'] === 'kanepe',
    ));

    // Two sofas facing each other across a room is an arrangement somebody chose. Hanging the
    // second one off the side of the first is not.
    expect($sofas)->toHaveCount(2)
        ->and($sofas[0]['rotation_y_deg'])->not->toBe($sofas[1]['rotation_y_deg']);
});

it('oturma grubu: falls back to a wall when the group has no room for a wing', function (): void {
    $narrow = new RoomGeometryVersion;
    $narrow->forceFill(['width_mm' => 2_600, 'length_mm' => 4_000, 'height_mm' => 2_600]);

    $result = $this->composer->compose($narrow, [], [
        piece('kanepe', 1_700, 900, 'north', 780),
        piece('koltuk', 780, 820, 'east', 820),
    ]);

    $chair = find($result['items'], 'koltuk');

    // 2.6 m of room cannot hold a sofa, a table and a chair beside it. The chair takes the
    // wall the plan named rather than standing half outside the room.
    expect($chair)->not->toBeNull()
        ->and($chair['rotation_y_deg'])->toBe(90);
});

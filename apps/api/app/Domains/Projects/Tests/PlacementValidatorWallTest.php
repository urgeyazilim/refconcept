<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Services\PlacementValidator;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Which pieces are measured against a wall, and which are not.
 *
 * This exists because of one design. A customer chose a corner sofa, a rug and curtains for
 * a room whose longest wall is 3500 mm, and all three were refused: the sofa at 3850, the
 * rug at 4400, the curtains at 5220. None of the three touches that wall — the plan placed
 * the sofa in the middle of the room, a rug lies on the floor, and a curtain's fabric is two
 * to two and a half times the window because it gathers.
 *
 * What it cost was not three missing rows. The renderer received a living room with a
 * television, a coffee table and nowhere to sit, and `gpt-image-2` completed the scene: it
 * drew a corner sofa nobody could buy and shifted the walls to fit it. So the arithmetic
 * below is not a detail — it is what stands between a customer and a picture of furniture
 * that is not for sale.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->validator = app(PlacementValidator::class);

    $project = Project::factory()->withRoom()->create();
    $this->room = $project->rooms()->firstOrFail();

    // The room from the design that prompted this: the long wall is 3500 mm.
    $this->room->forceFill(['width_mm' => 3_500, 'length_mm' => 4_200, 'height_mm' => 2_700])->save();
    $this->room->refresh();
});

/**
 * The validator answer, split into the two halves the assertions care about.
 *
 * @param  list<array<string, mixed>>  $placements
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function split(PlacementValidator $validator, Room $room, array $placements): array
{
    $result = $validator->check($room, $placements);

    return [array_values($result['accepted']), array_values($result['rejected'])];
}

it('keeps a sofa that stands in the middle of the room', function (): void {
    // The plan's own words were "in the middle of the room, at least 40 cm clear of the
    // wall". Measuring it against that wall answered a question nobody asked.
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'Köşe koltuk',
        'category' => 'oturma-grubu',
        'wall' => 'north',
        'max_width_mm' => 3_850,
        'quantity' => 1,
    ]]);

    expect($kept)->toHaveCount(1)
        ->and($rejected)->toHaveCount(0);
});

it('keeps a rug wider than the wall it is named against', function (): void {
    // A rug lies on the floor. The wall it "belongs to" is the seating group's wall, which
    // is a description of where in the room it is, not of what limits its size.
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'Halı',
        'category' => 'hali',
        'wall' => 'north',
        'max_width_mm' => 4_400,
        'quantity' => 1,
    ]]);

    expect($kept)->toHaveCount(1)
        ->and($rejected)->toHaveCount(0);
});

it('keeps curtains whose fabric is wider than the wall', function (): void {
    // Gathered fabric is two to two and a half times the window. Comparing it to a wall
    // length is wrong by construction, not by a margin.
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'Perde',
        'category' => 'perde',
        'wall' => 'south',
        'max_width_mm' => 5_220,
        'quantity' => 1,
    ]]);

    expect($kept)->toHaveCount(1)
        ->and($rejected)->toHaveCount(0);
});

it('still refuses a wall unit that does not fit its wall', function (): void {
    /*
     * The check has to keep working where it means something. A 4200 mm television unit
     * against a 3500 mm wall is the failure nobody notices until a delivery van arrives,
     * which is why this class was written in the first place.
     */
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'TV ünitesi',
        'category' => 'tv-unitesi',
        'wall' => 'north',
        'max_width_mm' => 4_200,
        'quantity' => 1,
    ]]);

    expect($kept)->toHaveCount(0)
        ->and($rejected)->toHaveCount(1)
        ->and($rejected[0]['reason'])->toContain('sığmıyor');
});

it('still refuses a console wider than its wall', function (): void {
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'Konsol',
        'category' => 'konsol',
        'wall' => 'west',
        'max_width_mm' => 6_000,
        'quantity' => 1,
    ]]);

    expect($kept)->toHaveCount(0)
        ->and($rejected)->toHaveCount(1);
});

it('still refuses anything without a category', function (): void {
    // Unchanged, and the reason is unchanged: with no category the matcher cannot search,
    // the shopping list comes back empty, and the renderer draws furniture out of its head.
    [$kept, $rejected] = split($this->validator, $this->room, [[
        'name' => 'Bir şey',
        'wall' => 'north',
        'max_width_mm' => 800,
    ]]);

    expect($kept)->toHaveCount(0)
        ->and($rejected)->toHaveCount(1);
});

<?php

declare(strict_types=1);

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use App\Domains\Projects\Services\RoomGeometryProposer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * What the photograph said about the size of the room, and what is done with it.
 *
 * The estimate is made the way a person would make it — from a doorway, a floor tile, the
 * height of a socket — and is right to within a hand's width most of the time and wrong by
 * half a metre occasionally. So it is written down as something to agree to. Everything here
 * is about the difference between those two things.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->proposer = app(RoomGeometryProposer::class);

    $project = Project::factory()->withRoom()->create();
    $this->room = $project->rooms()->firstOrFail();
});

/**
 * An analysis with whatever the model is being said to have reported.
 *
 * @param  array<string, mixed>  $payload
 */
function analysed(array $payload): RoomAnalysis
{
    $media = test()->room->media()->create([
        'disk' => 'room-photos',
        'storage_path' => 'test/'.Str::uuid7().'.jpg',
        'original_name' => 'oda.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1_024,
        'checksum_sha256' => hash('sha256', 'oda'),
        'type' => 'photo',
    ]);

    $analysis = RoomAnalysis::query()->create([
        'room_id' => test()->room->getKey(),
        'media_id' => $media->getKey(),
        'payload' => $payload,
        'is_current' => true,
    ]);

    $analysis->setRelation('room', test()->room);

    return $analysis;
}

it('proposes the measurements it read, unconfirmed', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720, 'confidence' => 0.72],
    ]));

    expect($version)->not->toBeNull()
        ->and($version->width_mm)->toBe(4_850)
        ->and($version->source)->toBe('ai')
        // The whole point. Until somebody says yes it is a guess with a decimal point on it.
        ->and($version->is_confirmed)->toBeFalse()
        ->and($version->confidence_bps)->toBe(7_200);
});

it('says nothing rather than guessing when the photograph did not tell it', function (): void {
    expect($this->proposer->propose(analysed(['fixed_elements' => []])))->toBeNull();
});

it('drops an estimate that cannot be a room', function (): void {
    // 485 mm across. A misread reference — a doll's house door, a photograph of a photograph —
    // and a number that would be proposed to the customer as their living room.
    expect($this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 485, 'length_mm' => 5_200, 'height_mm' => 2_720],
    ])))->toBeNull();
});

it('does not ask again about a room whose measurements are already agreed', function (): void {
    RoomGeometryVersion::query()->create([
        'room_id' => $this->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 4_000,
        'length_mm' => 4_000,
        'height_mm' => 2_600,
    ])->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();

    // Re-reading a photograph should not put a question back in front of somebody who has
    // answered it.
    expect($this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
    ])))->toBeNull();
});

it('proposes once per analysis rather than once per reading', function (): void {
    $analysis = analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
    ]);

    $first = $this->proposer->propose($analysis);
    $second = $this->proposer->propose($analysis);

    expect($second->getKey())->toBe($first->getKey())
        ->and(RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->count())->toBe(1);
});

// --- openings ---------------------------------------------------------------------

it('puts the openings it read straight onto the walls', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            ['type' => 'door', 'wall' => 'east', 'offset_mm' => 400, 'width_mm' => 900, 'height_mm' => 2_100],
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 720, 'width_mm' => 1_800, 'sill_height_mm' => 900],
        ],
    ]));

    /*
     * The photograph is taken so that nobody has to do this by hand.
     *
     * They were held on the proposal until the size was agreed to, on the grounds that a door
     * nobody had been shown should not appear in their room. It read as the opposite: the
     * reading found a window and a door, the room step drew an empty box, and the panel said
     * it could not find either. They go on the walls now, in front of the question the step
     * already asks, marked as the photograph's and correctable in a tap.
     */
    expect($version->payload['openings'])->toHaveCount(2)
        ->and(RoomConstraint::query()->where('room_id', $this->room->getKey())->count())->toBe(2)
        ->and(RoomConstraint::query()->where('room_id', $this->room->getKey())->pluck('notes')->unique()->all())
        ->toBe(['Fotoğraftan tespit edildi.']);
});

it('refuses an opening wider than the wall it claims', function (): void {
    $version = $this->proposer->propose(analysed([
        // Four metres across the wall you face, six deep. So north is four metres long.
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 6_000, 'height_mm' => 2_800],
        'openings' => [
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 0, 'width_mm' => 5_000, 'height_mm' => 1_400],
        ],
    ]));

    /*
     * The wall names are defined against the photograph and the measurements against the wall
     * names, so the two can disagree — and when they do the room comes out with its
     * proportions the wrong way round and a window hanging off the end of a wall.
     */
    expect($version->payload['openings'])->toBe([]);
});

it('slides an opening back onto its wall rather than losing it', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 6_000, 'height_mm' => 2_800],
        'openings' => [
            // Three and a half metres along a four-metre wall, and 1.2 m wide: a third of it
            // would be past the corner.
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 3_500, 'width_mm' => 1_200, 'height_mm' => 1_400],
        ],
    ]));

    // Where it is was a guess; that it exists was not.
    expect(RoomConstraint::query()->where('room_id', $this->room->getKey())->value('offset_mm'))->toBe(2_800);
});

it('takes the kind of window the reading named', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            // A metre and a bit of glass: the width alone would call this a double casement.
            // The reading looked at it and counted one sash.
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 400, 'width_mm' => 1_200, 'sill_height_mm' => 400, 'variant' => 'single'],
        ],
    ]));

    expect(RoomConstraint::query()->where('room_id', $this->room->getKey())->first()?->variant?->value)->toBe('single');
});

it('ignores a kind that opening cannot be', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            // "Sliding" belongs to a balcony door. On a window it is a misread, and a room
            // drawn from it is wrong in a way nobody can explain.
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 400, 'width_mm' => 2_100, 'sill_height_mm' => 900, 'variant' => 'sliding'],
        ],
    ]));

    expect(RoomConstraint::query()->where('room_id', $this->room->getKey())->first()?->variant?->value)->toBe('triple');
});

it('writes the radiators and sconces it saw into the room', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'fixed_elements' => [
            ['type' => 'wall_sconce', 'wall' => 'north'],
            ['type' => 'wall_sconce', 'wall' => 'north'],
            ['type' => 'radiator', 'wall' => 'east'],
            // Trim is drawn as part of the room, not listed as something to work around.
            ['type' => 'baseboard', 'wall' => 'all'],
            ['type' => 'crown_molding', 'wall' => 'all'],
        ],
    ]));

    $kinds = RoomConstraint::query()
        ->where('room_id', $this->room->getKey())
        ->orderBy('type')
        ->get()
        ->map(fn (RoomConstraint $c): string => $c->type->value)
        ->all();

    $radiator = RoomConstraint::query()->where('room_id', $this->room->getKey())->where('type', 'radiator')->firstOrFail();

    // Called what it is, not what category it falls into: three rows saying "Diğer" tell the
    // customer nothing about their own room.
    expect(RoomConstraint::query()->where('type', 'other')->value('label'))->toBe('Aplik')
        ->and($kinds)->toBe(['other', 'other', 'radiator'])
        // A bookcase planned across a radiator is a radiator nobody can use again.
        ->and($radiator->is_blocking)->toBeTrue()
        ->and($radiator->notes)->toBe('Fotoğraftan tespit edildi.');
});

it('leaves the fixtures alone when the room already has some of its own', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'radiator',
        'wall' => 'south',
    ]);

    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'fixed_elements' => [['type' => 'radiator', 'wall' => 'east']],
    ]));

    expect(RoomConstraint::query()->where('room_id', $this->room->getKey())->count())->toBe(1);
});

it('drops an opening it cannot place', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            // No wall: it cannot be drawn anywhere, and putting it somewhere would be a hole
            // in a wall of the customer's room that does not have one.
            ['type' => 'window', 'offset_mm' => 720, 'width_mm' => 1_800],
            ['type' => 'door', 'wall' => 'upstairs', 'offset_mm' => 400, 'width_mm' => 900],
            ['type' => 'door', 'wall' => 'east', 'offset_mm' => 400, 'width_mm' => 9_000],
        ],
    ]));

    expect($version->payload['openings'])->toBe([]);
});

it('says where an opening it read came from', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            ['type' => 'door', 'wall' => 'east', 'offset_mm' => 400, 'width_mm' => 900, 'height_mm' => 2_100],
        ],
    ]));

    $constraint = RoomConstraint::query()->where('room_id', $this->room->getKey())->firstOrFail();

    // Said plainly, so the customer can see at a glance which entries are their own.
    expect($constraint->wall)->toBe('east')
        ->and($constraint->notes)->toBe('Fotoğraftan tespit edildi.')
        ->and($constraint->source)->toBe('ai');

    $this->proposer->adoptOpenings($version);

    // Asking again leaves one door on that wall, not two. A reading replaces what a reading
    // put there — which is how a customer whose window was read wrong can have it read again
    // — and two doors beside each other is the mess that rule has to avoid while it does.
    expect(RoomConstraint::query()->where('room_id', $this->room->getKey())->count())->toBe(1);
});

it('says which kind an adopted opening most likely is, from its width', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            ['type' => 'door', 'wall' => 'east', 'offset_mm' => 400, 'width_mm' => 1_600, 'height_mm' => 2_100],
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 720, 'width_mm' => 2_100, 'sill_height_mm' => 900],
            ['type' => 'window', 'wall' => 'south', 'offset_mm' => 720, 'width_mm' => 1_200, 'sill_height_mm' => 0],
        ],
    ]));

    $this->proposer->adoptOpenings($version);

    /*
     * The reading is not asked which kind; the width is the only evidence. Wrong is cheap —
     * one tap on the chip — and a room drawn with three panes where there are three panes
     * is the room the customer recognises.
     */
    $kinds = RoomConstraint::query()->where('room_id', $this->room->getKey())->orderBy('wall')
        ->get()->mapWithKeys(fn (RoomConstraint $c): array => [$c->wall => $c->variant?->value])->all();

    expect($kinds)->toBe(['east' => 'double_door', 'north' => 'triple', 'south' => 'french_balcony']);
});

it('leaves a room alone when it already has openings of its own', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'window',
        'wall' => 'north',
        'offset_mm' => 700,
        'width_mm' => 1_600,
    ]);

    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_850, 'length_mm' => 5_200, 'height_mm' => 2_720],
        'openings' => [
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 720, 'width_mm' => 1_800],
        ],
    ]));

    // Two windows a hand's width apart, and no way to tell from here which is the real one.
    expect($this->proposer->adoptOpenings($version))->toBe(0)
        ->and(RoomConstraint::query()->where('room_id', $this->room->getKey())->count())->toBe(1);
});

it('puts the door a later reading found into a room whose size was already agreed', function (): void {
    // The size was agreed before any reading placed a door: a sealed box, so far.
    RoomGeometryVersion::query()->create([
        'room_id' => $this->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 4_850,
        'length_mm' => 5_200,
        'height_mm' => 2_720,
    ])->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();

    $proposal = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 3_800, 'length_mm' => 5_000, 'height_mm' => 2_600],
        'openings' => [
            ['type' => 'door', 'wall' => 'east', 'offset_mm' => 1_800, 'width_mm' => 900, 'height_mm' => 2_100],
            ['type' => 'window', 'wall' => 'north', 'offset_mm' => 200, 'width_mm' => 3_400, 'height_mm' => 1_600, 'sill_height_mm' => 900],
        ],
    ]));

    // No second size to agree to — but the door and the window are in the room now.
    expect($proposal)->toBeNull()
        ->and(RoomConstraint::query()->where('room_id', $this->room->getKey())->count())->toBe(2);
});

/*
 * --- where along the wall -------------------------------------------------
 *
 * The reading used to be asked for millimetres. Nobody can measure a millimetre from a
 * photograph, and the model does not refuse: the product owner's room came back with nine
 * round numbers at a stated confidence of 0.7, and they could see what they were. Since
 * prompt v11 it is asked what proportion of the wall the opening covers — a judgement about
 * a picture — and the millimetres are worked out from the wall.
 */

it('oran: works the opening out from the wall it is on', function (): void {
    $version = $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [[
            'type' => 'window',
            'wall' => 'west',
            'starts_at' => 0.3,
            'ends_at' => 0.8,
            'sill_ratio' => 0.25,
            'head_ratio' => 0.85,
        ]],
    ]));

    $window = $this->room->constraints()->firstOrFail();

    // The west wall is the room's length: 5500. Three tenths along is 1650, half its length
    // is 2750 wide. The height is the room's: a quarter up is 650, to 0.85 is 2210.
    expect($version)->not->toBeNull()
        ->and($window->offset_mm)->toBe(1_650)
        ->and($window->width_mm)->toBe(2_750)
        ->and($window->sill_height_mm)->toBe(650)
        ->and($window->height_mm)->toBe(1_560);
});

it('oran: an opening cannot overrun the end of its own wall', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [[
            'type' => 'door',
            'wall' => 'north',
            'starts_at' => 0.8,
            'ends_at' => 1.0,
        ]],
    ]));

    $door = $this->room->constraints()->firstOrFail();

    // A proportion of a wall is on that wall by construction. The clamp that used to slide a
    // millimetre estimate back from over the corner has nothing left to do.
    expect($door->offset_mm + $door->width_mm)->toBe(4_000);
});

it('oran: still honours a reading that answers in millimetres', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [[
            'type' => 'window',
            'wall' => 'north',
            'offset_mm' => 900,
            'width_mm' => 1_400,
            'height_mm' => 1_400,
            'sill_height_mm' => 900,
        ]],
    ]));

    $window = $this->room->constraints()->firstOrFail();

    // Rooms read before the prompt changed are still rooms, and so is a reading that leaves
    // the proportions out.
    expect($window->offset_mm)->toBe(900)
        ->and($window->width_mm)->toBe(1_400)
        ->and($window->sill_height_mm)->toBe(900);
});

it('oran: ignores a pair of proportions that is not a stretch of wall', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [[
            'type' => 'window',
            'wall' => 'north',
            // The ends the wrong way round, and millimetres beside them.
            'starts_at' => 0.9,
            'ends_at' => 0.2,
            'offset_mm' => 900,
            'width_mm' => 1_400,
        ]],
    ]));

    $window = $this->room->constraints()->firstOrFail();

    // Half an answer is not salvaged into a whole one. The millimetres are what is left.
    expect($window->offset_mm)->toBe(900)
        ->and($window->width_mm)->toBe(1_400);
});

it('oran: drops an opening that answers neither way', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [[
            'type' => 'window',
            'wall' => 'north',
            'starts_at' => 0.3,
        ]],
    ]));

    // A start with no end is not a window, and a window with no position is a hole of
    // invented size in somebody's wall.
    expect($this->room->constraints()->count())->toBe(0);
});

/*
 * --- reading the photographs again ----------------------------------------
 *
 * Openings used to be written into a room only when it had none, which meant the first
 * reading was the last: from the moment it put a door and a window on the walls, the room
 * *had* openings and every later reading of the same photographs was discarded. The product
 * owner asked for the room again and got the same room, because nothing they could press
 * would ever move a wall.
 */

it('yeniden oku: replaces what the last reading put on the walls', function (): void {
    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [['type' => 'window', 'wall' => 'north', 'starts_at' => 0.1, 'ends_at' => 0.4]],
    ]));

    expect($this->room->constraints()->firstOrFail()->offset_mm)->toBe(400);

    // A room has one current reading, so the old one steps aside the way the analyser does
    // it when the photographs are read again.
    RoomAnalysis::query()->where('room_id', $this->room->getKey())->update(['is_current' => false]);

    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [['type' => 'window', 'wall' => 'north', 'starts_at' => 0.5, 'ends_at' => 0.9]],
    ]));

    $openings = $this->room->constraints()->get();

    // One window, where the newer reading put it. Two windows on one wall is not a second
    // opinion, it is a room with a window that does not exist.
    expect($openings)->toHaveCount(1)
        ->and($openings->first()->offset_mm)->toBe(2_000)
        ->and($openings->first()->source)->toBe('ai');
});

it('yeniden oku: leaves the walls the customer answered for alone', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'window',
        'wall' => 'south',
        'offset_mm' => 1_000,
        'width_mm' => 1_200,
        'height_mm' => 1_400,
    ]);

    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [['type' => 'window', 'wall' => 'north', 'starts_at' => 0.5, 'ends_at' => 0.9]],
    ]));

    $openings = $this->room->constraints()->get();

    /*
     * Wall by wall. The south wall is the customer's and stays exactly as they left it; the
     * north wall is nobody's yet, so the reading is allowed its answer there.
     *
     * The product owner's own room is why it is not all or nothing: they had corrected the
     * door on one wall and left the window on another as the reading found it, and a rule
     * that refused whenever anything was theirs would have locked the wall they still wanted
     * read.
     */
    expect($openings)->toHaveCount(2)
        ->and($openings->firstWhere('wall', 'south')->offset_mm)->toBe(1_000)
        ->and($openings->firstWhere('wall', 'south')->source)->toBe('user')
        ->and($openings->firstWhere('wall', 'north')->source)->toBe('ai');
});

it('yeniden oku: will not put a second door beside the one the customer corrected', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'north',
        'offset_mm' => 1_000,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    $this->proposer->propose(analysed([
        'estimated_dimensions' => ['width_mm' => 4_000, 'length_mm' => 5_500, 'height_mm' => 2_600],
        'openings' => [['type' => 'door', 'wall' => 'north', 'starts_at' => 0.5, 'ends_at' => 0.7]],
    ]));

    // They looked at the same photograph and at their own room. A second door beside theirs
    // is not a second opinion, it is a door that does not exist.
    expect($this->room->constraints()->count())->toBe(1)
        ->and($this->room->constraints()->firstOrFail()->offset_mm)->toBe(1_000);
});

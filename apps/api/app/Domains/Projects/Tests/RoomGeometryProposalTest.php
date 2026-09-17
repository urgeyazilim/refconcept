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
        // Asking again changes nothing: the door is already on that wall, and a second one
        // beside it is the mess this guard exists to prevent.
        ->and($this->proposer->adoptOpenings($version))->toBe(0);
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

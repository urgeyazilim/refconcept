<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\PrimaryPhotoChooser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Choosing which photograph a design is drawn from, without asking.
 *
 * The screen used to put "use this one" beside every photograph after the first, which read
 * as a choice about which of them counted — to a customer who had just been told to take four
 * corners. The product owner's answer was the one they have given all along: the system knows
 * enough to decide, so it should decide.
 *
 * Every photograph is read whatever this picks; what is chosen here is the viewpoint of the
 * finished picture, because a render is one view of a room.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();
    $this->chooser = app(PrimaryPhotoChooser::class);

    $this->photo = function (int $width, int $height, int $position = 0, string $type = 'photo', ?string $from = null) use (&$c): RoomMedia {
        return RoomMedia::query()->create([
            'room_id' => $this->room->getKey(),
            'type' => $type,
            'source_media_id' => $from,
            'disk' => 'room-photos',
            'storage_path' => 'test/'.Str::uuid7().'.jpg',
            'original_name' => 'kose.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1_024,
            'checksum_sha256' => hash('sha256', (string) Str::uuid7()),
            'width' => $width,
            'height' => $height,
            'position' => $position,
        ]);
    };
});

it('prefers the photograph taken sideways', function (): void {
    $upright = ($this->photo)(1_080, 1_920, 0);
    $sideways = ($this->photo)(1_600, 1_200, 1);

    $this->room->forceFill(['primary_media_id' => $upright->getKey()])->save();

    /*
     * A render is a wide picture of a room, so an upright photograph hands the model a strip
     * of one: ceiling and floor, with the walls the furniture goes against cut off at both
     * sides. The product owner worked this out before we did.
     */
    expect($this->chooser->choose($this->room))->toBeTrue()
        ->and((string) $this->room->fresh()->primary_media_id)->toBe((string) $sideways->getKey());
});

it('prefers the corner that shows most of the room', function (): void {
    $bare = ($this->photo)(1_600, 1_200, 0);
    $full = ($this->photo)(1_600, 1_200, 1);

    RoomAnalysis::query()->create([
        'room_id' => $this->room->getKey(),
        'media_id' => $bare->getKey(),
        'payload' => [
            'photo_ids' => [(string) $bare->getKey(), (string) $full->getKey()],
            'fixed_elements' => [
                ['type' => 'window', 'photo_index' => 1],
                ['type' => 'door', 'photo_index' => 1],
                ['type' => 'radiator', 'photo_index' => 1],
            ],
        ],
        'is_current' => true,
    ]);

    $this->chooser->choose($this->room);

    // The corner that caught the window, the door and the radiator is the corner that shows
    // the room; both are the same shape and size, so this is the only thing between them.
    expect((string) $this->room->fresh()->primary_media_id)->toBe((string) $full->getKey());
});

it('prefers the emptied room over the one it was made from', function (): void {
    $photo = ($this->photo)(1_600, 1_200, 0);
    $plate = ($this->photo)(1_600, 1_200, 1, 'plate', (string) $photo->getKey());

    $this->chooser->choose($this->room);

    // Same view of the same room with nothing in the way, which is what a design wants to
    // start on. The reading never looked at the plate, so it has to be scored as its source.
    expect((string) $this->room->fresh()->primary_media_id)->toBe((string) $plate->getKey());
});

it('leaves a customer who chose for themselves alone', function (): void {
    $upright = ($this->photo)(1_080, 1_920, 0);
    ($this->photo)(1_600, 1_200, 1);

    $this->chooser->chosenByCustomer($this->room, $upright);

    /*
     * They disagreed with us on purpose, and the next photograph they upload must not quietly
     * overrule them. The upright one is the one we would never pick, which is exactly why it
     * is the one worth testing.
     */
    expect($this->chooser->choose($this->room->fresh()))->toBeFalse()
        ->and((string) $this->room->fresh()->primary_media_id)->toBe((string) $upright->getKey());
});

it('says nothing about a room with no photographs', function (): void {
    expect($this->chooser->best($this->room))->toBeNull()
        ->and($this->chooser->choose($this->room))->toBeFalse();
});

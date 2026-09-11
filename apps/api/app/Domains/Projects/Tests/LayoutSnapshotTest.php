<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomGeometryVersion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * The picture of the plan, and who may see it.
 *
 * It is a drawing rather than a photograph, and that changes nothing: the walls are the
 * customer's walls, the windows are where their windows are, and the furniture is what they
 * are about to buy. So it goes on the private disk beside their photographs, under the same
 * rules, and no response ever carries a path to it.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('s3');

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

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

/** A one-pixel PNG, as a canvas would hand it over. */
function pngDataUrl(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

it('keeps the picture and tells the client nothing about where', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/snapshot", ['image' => pngDataUrl()])
        ->assertOk();

    $layout = DesignLayout::query()->where('room_id', $this->room->getKey())->firstOrFail();

    expect($layout->snapshot_path)->not->toBeNull()
        ->and($layout->snapshot_taken_at)->not->toBeNull()
        ->and(Storage::disk('s3')->exists((string) $layout->snapshot_path))->toBeTrue()
        // The client knows it worked. It has no business knowing where a picture of somebody's
        // home is kept, and a path in a response is a path in a log, a bug report and a
        // screenshot.
        ->and(json_encode($response->json()))->not->toContain('layout-snapshots');
});

it('refuses anything that is not actually a PNG', function (): void {
    // The prefix is whatever the caller typed, so the bytes are read rather than the header
    // believed. This one says PNG and is a text file.
    $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/snapshot", [
            'image' => 'data:image/png;base64,'.base64_encode('<?php echo "merhaba";'),
        ])
        ->assertStatus(422);
});

it('replaces the previous picture rather than collecting them', function (): void {
    $this->actingAs($this->owner)->postJson("{$this->url}/layout/snapshot", ['image' => pngDataUrl()])->assertOk();

    $first = DesignLayout::query()->where('room_id', $this->room->getKey())->firstOrFail()->snapshot_path;

    $this->actingAs($this->owner)->postJson("{$this->url}/layout/snapshot", ['image' => pngDataUrl()])->assertOk();

    $second = DesignLayout::query()->where('room_id', $this->room->getKey())->firstOrFail()->snapshot_path;

    // One layout, one current picture: it is regenerated every time the furniture moves, and
    // the renderer only ever reads the most recent one.
    expect($second)->not->toBe($first);
});

it('will not take a picture of a room whose measurements nobody agreed to', function (): void {
    RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->update(['is_confirmed' => false]);

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/snapshot", ['image' => pngDataUrl()])
        ->assertStatus(422);
});

it('keeps a stranger out', function (): void {
    $this->actingAs($this->stranger)
        ->postJson("{$this->url}/layout/snapshot", ['image' => pngDataUrl()])
        ->assertForbidden();
});

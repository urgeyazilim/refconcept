<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Photographs of somebody's home.
 *
 * The tests here are almost all about what *cannot* happen. A room photograph shows
 * what a person owns and who they live with; the failure mode is not a broken feature
 * but a stranger looking at a child's bedroom, and no apology fixes that afterwards.
 *
 * Three properties are asserted from several directions: the file is never on a public
 * disk, no response ever contains a path or an unguarded URL, and the bytes themselves
 * are behind the same policy as the metadata.
 */
beforeEach(function (): void {
    Storage::fake('s3');
    config()->set('refconcept.storage.private_disk', 's3');

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    $this->mediaUrl = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}/media";
});

/** Uploads one photograph as the owner and returns the row. */
function uploadPhoto(string $name = 'oturma-odasi.jpg', int $width = 1600, int $height = 1200): RoomMedia
{
    test()->actingAs(test()->owner)
        ->postJson(test()->mediaUrl, ['file' => UploadedFile::fake()->image($name, $width, $height)])
        ->assertCreated();

    // Ordered by id rather than created_at: UUIDv7 is time-ordered *and* unique, and
    // two uploads inside the same second tie on the timestamp.
    return RoomMedia::query()->orderByDesc('id')->firstOrFail();
}

// --- where the bytes go ------------------------------------------------------------

it('stores a photograph on the private disk under a random key', function (): void {
    $media = uploadPhoto('salon.jpg');

    expect($media->disk)->toBe('s3')
        ->and($media->storage_path)->toStartWith('room-media/')
        // The filename carries nothing guessable: a leaked path for one photograph
        // must not be a directory listing for the rest.
        ->and($media->storage_path)->not->toContain('salon')
        ->and($media->checksum_sha256)->toHaveLength(64);

    Storage::disk('s3')->assertExists($media->storage_path);
});

it('never puts a room photograph on the public bucket', function (): void {
    Storage::fake('s3-public');

    uploadPhoto();

    // There is no configuration under which these land next to product imagery.
    expect(Storage::disk('s3-public')->allFiles())->toBe([]);
});

it('never returns a storage path or a URL in a listing', function (): void {
    uploadPhoto();

    $response = $this->actingAs($this->owner)->getJson($this->mediaUrl)->assertOk();

    $body = json_encode($response->json());

    // A link is a separate, deliberate request. Putting one in every listing means a
    // URL for somebody's living room in every log, cache and error report.
    expect($body)->not->toContain('room-media/')
        ->and($response->json('data.0'))->not->toHaveKey('url')
        ->and($response->json('data.0'))->not->toHaveKey('storage_path');
});

it('issues a short-lived link only after the ownership check', function (): void {
    $media = uploadPhoto();

    $response = $this->actingAs($this->owner)
        ->getJson("{$this->mediaUrl}/{$media->getKey()}/link")
        ->assertOk();

    expect($response->json('data.url'))->toBeString()
        ->and($response->json('data.expires_in'))->toBe(300);

    $this->actingAs($this->stranger)
        ->getJson("{$this->mediaUrl}/{$media->getKey()}/link")
        ->assertForbidden();
});

it('puts the bytes behind the same policy as the metadata', function (): void {
    $media = uploadPhoto();

    $this->actingAs($this->owner)
        ->get("/api/v1/projects/room-media/{$media->getKey()}/download")
        ->assertOk()
        // Symfony normalises and reorders the directives; what matters is that all
        // three are present, not the order it chose to print them in.
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // The download route is what a signed-URL fallback points at, so it has to be
    // guarded as tightly as the JSON — a public streaming route would make every other
    // check decorative.
    $this->actingAs($this->stranger)
        ->get("/api/v1/projects/room-media/{$media->getKey()}/download")
        ->assertForbidden();

});

it('refuses the bytes to somebody who is not signed in at all', function (): void {
    // Its own test rather than a third assertion above: actingAs() persists for the
    // rest of a test, so an "anonymous" request made after one would quietly still be
    // authenticated — and would pass for the wrong reason.
    $media = uploadPhoto();

    auth()->forgetGuards();

    $this->getJson("/api/v1/projects/room-media/{$media->getKey()}/download")
        ->assertUnauthorized();
});

it('names download routes that actually exist', function (): void {
    $media = uploadPhoto();

    /*
     * The fallback path is the one nothing exercises. Every environment RefConcept is
     * tested in can sign a URL — MinIO in development, the faked disk here — so a typo
     * in the fallback's route name would surface only in production, on the one
     * deployment configured without object storage, as a 500 on every "view photo".
     *
     * That is not hypothetical: the seller-document equivalent shipped in Phase 2 with
     * an "api." prefix the router never applies, and this is the assertion that would
     * have caught it. Both are checked here.
     */
    expect(fn (): string => route('v1.projects.room-media.download', ['medium' => $media->getKey()]))
        ->not->toThrow(Exception::class);

    expect(fn (): string => route('v1.projects.design-assets.download', ['asset' => $media->getKey()]))
        ->not->toThrow(Exception::class);

    expect(fn (): string => route('v1.seller.documents.download', ['document' => $media->getKey()]))
        ->not->toThrow(Exception::class);
});

// --- what may be uploaded ------------------------------------------------------------

it('refuses a file that is not a photograph', function (): void {
    $this->actingAs($this->owner)
        ->postJson($this->mediaUrl, ['file' => UploadedFile::fake()->create('plan.pdf', 200, 'application/pdf')])
        ->assertStatus(422);

    expect(RoomMedia::query()->count())->toBe(0);
});

it('refuses a photograph too small to design from', function (): void {
    $this->actingAs($this->owner)
        ->postJson($this->mediaUrl, ['file' => UploadedFile::fake()->image('kucuk.jpg', 320, 240)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    // Refused now rather than after the customer has spent credits on a design of a
    // blur.
    expect(RoomMedia::query()->count())->toBe(0);
});

it('caps the number of photographs per room', function (): void {
    foreach (range(1, RoomPhotoStorage::MAX_PER_ROOM) as $index) {
        uploadPhoto("foto-{$index}.jpg");
    }

    $this->actingAs($this->owner)
        ->postJson($this->mediaUrl, ['file' => UploadedFile::fake()->image('bir-fazla.jpg', 1600, 1200)])
        ->assertStatus(422);

    expect(RoomMedia::query()->count())->toBe(RoomPhotoStorage::MAX_PER_ROOM);
});

// --- the primary photograph -----------------------------------------------------------

it('makes the first photograph the one the design engine works from', function (): void {
    expect($this->room->isReadyForDesign())->toBeFalse();

    $media = uploadPhoto();

    // A customer who uploads one picture and finds the room still "not ready" has been
    // given a puzzle rather than a product.
    expect($this->room->fresh()->primary_media_id)->toBe($media->getKey())
        ->and($this->room->fresh()->isReadyForDesign())->toBeTrue();
});

it('promotes another photograph when the primary one is deleted', function (): void {
    $first = uploadPhoto('bir.jpg');
    $second = uploadPhoto('iki.jpg');

    expect($this->room->fresh()->primary_media_id)->toBe($first->getKey());

    $this->actingAs($this->owner)
        ->deleteJson("{$this->mediaUrl}/{$first->getKey()}")
        ->assertOk();

    expect($this->room->fresh()->primary_media_id)->toBe($second->getKey());
});

it('removes the bytes when a customer deletes a photograph', function (): void {
    $media = uploadPhoto();
    $path = $media->storage_path;

    $this->actingAs($this->owner)->deleteJson("{$this->mediaUrl}/{$media->getKey()}")->assertOk();

    // Unlike a seller's onboarding document there is no dispute this could later have
    // to answer, and keeping a picture of somebody's home after they asked for it gone
    // is indefensible.
    Storage::disk('s3')->assertMissing($path);
});

it('refuses to make a floor plan the photograph the engine works from', function (): void {
    $this->actingAs($this->owner)
        ->postJson($this->mediaUrl, [
            'file' => UploadedFile::fake()->image('plan.png', 2000, 1400),
            'type' => 'floor_plan',
        ])
        ->assertCreated();

    $plan = RoomMedia::query()->firstOrFail();

    // A floor plan is useful context and not a photograph of the room; designing from
    // one would produce a render of a drawing.
    expect($this->room->fresh()->primary_media_id)->toBeNull();

    $this->actingAs($this->owner)
        ->patchJson("{$this->mediaUrl}/{$plan->getKey()}", ['set_primary' => true])
        ->assertStatus(422);
});

// --- isolation --------------------------------------------------------------------------

it('never lets a stranger upload into somebody else room', function (): void {
    $this->actingAs($this->stranger)
        ->postJson($this->mediaUrl, ['file' => UploadedFile::fake()->image('sizma.jpg', 1600, 1200)])
        ->assertForbidden();

    expect(RoomMedia::query()->count())->toBe(0);
});

it('never lets a media id from another room be reached through this one', function (): void {
    $other = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $otherRoom = $other->rooms()->firstOrFail();

    $media = uploadPhoto();

    // 404 rather than 403: confirming the id exists elsewhere is itself a leak.
    $this->actingAs($this->owner)
        ->getJson("/api/v1/projects/{$other->getKey()}/rooms/{$otherRoom->getKey()}/media/{$media->getKey()}/link")
        ->assertNotFound();
});

it('keeps the filename out of the audit log', function (): void {
    uploadPhoto('bebek-odasi-yatak.jpg');

    $entry = DB::table('audit_logs')->where('action', 'projects.room_media.uploaded')->first();

    // The audit log is read by staff. "bebek-odasi-yatak.jpg" tells them something
    // they have no business knowing; the id and the size are enough to investigate.
    expect($entry)->not->toBeNull()
        ->and(json_encode($entry))->not->toContain('bebek-odasi');
});

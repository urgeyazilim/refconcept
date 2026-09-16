<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Jobs\ClearRoomPhotograph;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomAnalyser;
use App\Domains\Projects\Services\RoomClearer;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The plate: the room photograph with the furniture taken out.
 *
 * Rule K8 of the studio contract — movable things go, architecture stays — and rule K10,
 * once per photograph. Everything here is about what is kept: the plate lives beside the
 * photograph on the private disk, points back at it, replaces an earlier plate of the same
 * photograph rather than piling up, and is never a placeholder from the simulator.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();
    Storage::fake('s3');
    Storage::fake('s3-public');

    makeAiRoute(AiTask::RoomClear, ['credit_cost' => 0]);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    Storage::disk('s3')->put('room-media/'.$this->room->getKey().'/oda.jpg', 'jpeg-bytes');

    $this->photograph = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/oda.jpg',
        'original_name' => 'oda.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1_024,
        'checksum_sha256' => hash('sha256', 'oda'),
        'type' => 'photo',
        'uploaded_by' => $this->owner->getKey(),
    ]);

    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('queues the emptying and answers 202', function (): void {
    Queue::fake();

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/media/{$this->photograph->getKey()}/clear")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(ClearRoomPhotograph::class, fn (ClearRoomPhotograph $job): bool => $job->mediaId === (string) $this->photograph->getKey());
});

it('empties only photographs, and only for the room owner', function (): void {
    Queue::fake();

    $document = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/plan.pdf',
        'original_name' => 'plan.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1_024,
        'checksum_sha256' => hash('sha256', 'plan'),
        'type' => 'document',
    ]);

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/media/{$document->getKey()}/clear")
        ->assertStatus(422);

    $this->actingAs($this->stranger)
        ->postJson("{$this->url}/media/{$this->photograph->getKey()}/clear")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('throws away what the simulator answers rather than storing it', function (): void {
    /*
     * With no key on file the task routes to the fake provider, which succeeds and hands back
     * a placeholder picture. Stored, that would put a grey rectangle where the customer's
     * room should be, in every render from then on.
     */
    $plate = app(RoomClearer::class)->clear($this->room, $this->photograph);

    expect($plate)->toBeNull()
        ->and(RoomMedia::query()->where('type', 'plate')->exists())->toBeFalse();
});

it('keeps the plate beside its photograph, one per photograph, and the renderer starts from it', function (): void {
    $storage = app(RoomPhotoStorage::class);
    $files = app(GeneratedImageStore::class);

    $first = $storage->storePlate($this->room, $this->photograph, $files->stash('png-one', 'image/png'));

    expect($first->type)->toBe('plate')
        ->and($first->source_media_id)->toBe((string) $this->photograph->getKey())
        // The private disk, like the photograph: a customer's emptied living room reveals
        // rather than hides.
        ->and($first->disk)->toBe('s3')
        ->and(Storage::disk('s3')->get((string) $first->storage_path))->toBe('png-one')
        ->and($storage->plateOf($this->photograph)?->getKey())->toBe($first->getKey());

    // Made again: the earlier plate goes, file and row. One photograph, one plate.
    $second = $storage->storePlate($this->room, $this->photograph, $files->stash('png-two', 'image/png'));

    expect(RoomMedia::query()->where('type', 'plate')->count())->toBe(1)
        ->and(Storage::disk('s3')->exists((string) $first->storage_path))->toBeFalse()
        ->and($storage->plateOf($this->photograph)?->getKey())->toBe($second->getKey());

    // Asked for again over HTTP, the plate that exists is the answer; nothing is queued.
    Queue::fake();

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/media/{$this->photograph->getKey()}/clear")
        ->assertOk()
        ->assertJsonPath('data.type', 'plate')
        ->assertJsonPath('data.source_media_id', (string) $this->photograph->getKey());

    Queue::assertNothingPushed();
});

it('leaves in the room what the customer asked to keep, and remakes the plate for a new choice', function (): void {
    $analyser = app(RoomAnalyser::class);
    $analyser->store($this->room, (string) $this->photograph->getKey(), null, [
        'room_type' => 'living_room',
        'fixed_elements' => [['type' => 'window']],
        'surfaces' => ['floor' => ['material' => 'wood']],
        'movable_objects' => [
            ['type' => 'sofa', 'label' => 'Gri kanepe'],
            ['type' => 'rug', 'label' => 'Desenli halı'],
            ['type' => 'armchair', 'label' => 'Gri berjer'],
        ],
    ]);

    (new ClearRoomPhotograph((string) $this->photograph->getKey(), ['Gri kanepe']))->handle(app(RoomClearer::class));

    $job = AiJob::query()->where('task', AiTask::RoomClear->value)->latest('created_at')->firstOrFail();

    // The sofa is named as staying and is not in the list of what goes.
    expect($job->input['keep'])->toBe('Gri kanepe')
        ->and($job->input['objects'])->toContain('rug')
        ->and($job->input['objects'])->toContain('armchair')
        ->and($job->input['objects'])->not->toContain('sofa');

    // Asking again with a different choice queues a new plate rather than answering with
    // the old one; asking with no choice at all answers with whatever plate exists.
    Queue::fake();

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/media/{$this->photograph->getKey()}/clear", ['keep' => ['Gri kanepe', 'Desenli halı']])
        ->assertStatus(202);

    Queue::assertPushed(ClearRoomPhotograph::class, fn (ClearRoomPhotograph $job): bool => $job->keep === ['Gri kanepe', 'Desenli halı']);
});

it('makes the emptied photograph the primary one, unless the primary has a plate of its own', function (): void {
    $storage = app(RoomPhotoStorage::class);
    $files = app(GeneratedImageStore::class);

    // A second corner, marked primary; the first is the one that gets emptied.
    $second = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/oda-2.jpg',
        'original_name' => 'oda-2.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1_024,
        'checksum_sha256' => hash('sha256', 'oda-2'),
        'type' => 'photo',
        'uploaded_by' => $this->owner->getKey(),
        'position' => 1,
    ]);

    $this->room->forceFill(['primary_media_id' => $second->getKey()])->save();

    /*
     * The product owner emptied their first corner while the second was primary, and the
     * studio stood on step two asking about a photograph that was never going to be emptied.
     * The design is made from the plate, so the plated photograph is the one to design from.
     */
    $storage->storePlate($this->room, $this->photograph, $files->stash('png-one', 'image/png'));
    app(RoomClearer::class)->adoptAsPrimary($this->room, $this->photograph);

    expect($this->room->fresh()?->primary_media_id)->toBe((string) $this->photograph->getKey());

    // The primary already emptied: emptying another corner does not take its place.
    $storage->storePlate($this->room, $second, $files->stash('png-two', 'image/png'));
    app(RoomClearer::class)->adoptAsPrimary($this->room, $second);

    expect($this->room->fresh()?->primary_media_id)->toBe((string) $this->photograph->getKey());
});

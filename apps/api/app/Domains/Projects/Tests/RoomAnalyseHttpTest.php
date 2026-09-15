<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Jobs\AnalyseRoom;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomAnalyser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Reading a room from all of its photographs, on request and after an upload.
 *
 * A customer who photographs their room from four corners has said more than one picture
 * can; the reading has to hear all of it, and it has to happen without them starting a
 * design first — "Tanıma" is the second step of the studio, not a side effect of the fifth.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();
    Storage::fake('s3');

    makeAiRoute(AiTask::RoomAnalysis, ['credit_cost' => 1, 'max_attempts' => 1]);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();
    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";

    $this->photo = fn (int $position): RoomMedia => tap(RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'photo',
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey()."/kose-{$position}.jpg",
        'original_name' => "kose-{$position}.jpg",
        'mime_type' => 'image/jpeg',
        'size_bytes' => 200_000,
        'checksum_sha256' => hash('sha256', "kose-{$position}"),
        'position' => $position,
    ]), fn (RoomMedia $media) => Storage::disk('s3')->put($media->storage_path, 'jpeg-bytes'));
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('refuses to read a room with no photograph', function (): void {
    $this->actingAs($this->owner)->postJson("{$this->url}/analyse")->assertUnprocessable();
});

it('queues a reading of every photograph and answers 202', function (): void {
    Queue::fake();

    $first = ($this->photo)(0);
    ($this->photo)(1);
    ($this->photo)(2);
    $this->room->forceFill(['primary_media_id' => $first->getKey()])->save();

    $this->actingAs($this->owner)
        ->postJson("{$this->url}/analyse")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.photo_count', 3);

    Queue::assertPushed(AnalyseRoom::class, fn (AnalyseRoom $job): bool => count($job->photoIds) === 3 && $job->photoIds[0] === (string) $first->getKey());

    $this->actingAs($this->stranger)->postJson("{$this->url}/analyse")->assertForbidden();
});

it('reads all four corners as one room, the primary first, and says so on the room', function (): void {
    $photos = [($this->photo)(0), ($this->photo)(1), ($this->photo)(2), ($this->photo)(3)];
    // The customer chose the third picture as the one designs are made from.
    $this->room->forceFill(['primary_media_id' => $photos[2]->getKey()])->save();

    (new AnalyseRoom((string) $this->room->getKey(), app(RoomAnalyser::class)->photoIds($this->room->fresh())))
        ->handle(app(RoomAnalyser::class));

    $job = AiJob::query()->where('task', AiTask::RoomAnalysis->value)->firstOrFail();

    expect($job->input['image_sources'])->toHaveCount(4)
        ->and($job->input['image_sources'][0]['path'])->toBe($photos[2]->storage_path)
        ->and($job->input['photo_count'])->toBe(4);

    $analysis = RoomAnalysis::query()->where('room_id', $this->room->getKey())->current()->firstOrFail();

    expect($analysis->media_id)->toBe($photos[2]->getKey())
        ->and($analysis->payload['photo_ids'])->toHaveCount(4);

    $detail = $this->actingAs($this->owner)->getJson($this->url)->assertOk();

    expect($detail->json('data.analysis.photo_count'))->toBe(4)
        ->and($detail->json('data.analysis.is_stale'))->toBeFalse()
        ->and($detail->json('data.analysis.movable_objects'))->not->toBeEmpty();

    // Reading again with nothing changed is answered without queueing anything.
    Queue::fake();
    $this->actingAs($this->owner)->postJson("{$this->url}/analyse")->assertOk()->assertJsonPath('data.status', 'ready');
    Queue::assertNothingPushed();

    // A fifth photograph makes the reading stale, and the room says so.
    ($this->photo)(4);

    expect($this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.is_stale'))->toBeTrue();
});

it('stands down when the photographs changed since it was queued', function (): void {
    $first = ($this->photo)(0);
    $this->room->forceFill(['primary_media_id' => $first->getKey()])->save();

    $analyser = app(RoomAnalyser::class);
    $stale = $analyser->photoIds($this->room->fresh());

    // A second corner arrives before the first job runs.
    ($this->photo)(1);

    (new AnalyseRoom((string) $this->room->getKey(), $stale))->handle($analyser);

    // Nothing was read and nothing was spent: the job queued after the second upload has
    // the current set, and it is the one that reads.
    expect(FakeAiProvider::calls())->toBeEmpty()
        ->and(RoomAnalysis::query()->count())->toBe(0);

    (new AnalyseRoom((string) $this->room->getKey(), $analyser->photoIds($this->room->fresh())))->handle($analyser);

    expect(RoomAnalysis::query()->count())->toBe(1);
});

it('queues a delayed reading after a photograph is uploaded', function (): void {
    Queue::fake();

    $this->actingAs($this->owner)
        ->post("{$this->url}/media", ['file' => UploadedFile::fake()->image('salon.jpg', 1024, 768)])
        ->assertCreated();

    Queue::assertPushed(AnalyseRoom::class, fn (AnalyseRoom $job): bool => count($job->photoIds) === 1 && $job->delay !== null);
});

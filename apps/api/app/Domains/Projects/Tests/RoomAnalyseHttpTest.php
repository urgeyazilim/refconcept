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

it('hands the screen the colours and the cornice it read', function (): void {
    ($this->photo)(0);

    // Read here rather than through the endpoint: asking over HTTP queues the job and
    // answers 202, and what is being tested is what the room then hands the screen.
    app(RoomAnalyser::class)->forRoom($this->room, refresh: true);

    /*
     * The reading has always described the surfaces and only the renderer ever saw the
     * answer, so the planner drew every customer's room as the same cream box. The room
     * endpoint carries it now: what the walls are painted, what the ceiling is, what the
     * floor is made of, and whether there is a cornice to draw.
     */
    $surfaces = $this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.surfaces');

    expect($surfaces['wall_color'])->toBe('#8f8f8f')
        ->and($surfaces['ceiling_color'])->toBe('#f4f4f2')
        ->and($surfaces['floor'])->toBe('wood')
        ->and($surfaces['crown_molding'])->toBeTrue();
});

it('drops a colour it could not paint with', function (): void {
    ($this->photo)(0);

    // Read here rather than through the endpoint: asking over HTTP queues the job and
    // answers 202, and what is being tested is what the room then hands the screen.
    app(RoomAnalyser::class)->forRoom($this->room, refresh: true);

    // A model that answers "beyaz" should leave the room its default, not paint it black.
    RoomAnalysis::query()->firstOrFail()->forceFill([
        'surfaces' => ['walls' => ['material' => 'plaster', 'color_hex' => 'beyaz']],
    ])->save();

    $surfaces = $this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.surfaces');

    expect($surfaces['wall_color'])->toBeNull();
});

/*
 * --- what the reading says versus what the column holds --------------------
 *
 * A reading is a model answering in prose and some of the columns it lands in are short. The
 * first engine answered "estimated"; the one that reads rooms now answered "Standart iç kapı
 * boyutları ve dört fotoğrafın birlikte değerlendirilmesine dayalı, düşük güvenli yaklaşık
 * ölçülendirme", the insert was refused, the whole transaction rolled back, and a reading
 * that had already been made and paid for vanished. The customer watched "odanı okuyorum"
 * until they gave up and asked for two more readings of a room that had been read three
 * times, and nothing anywhere said why.
 */

it('keeps a reading whose measurement quality came back as a sentence', function (): void {
    $analyser = app(RoomAnalyser::class);

    $photo = ($this->photo)(0);

    $analysis = $analyser->store(
        $this->room,
        (string) $photo->getKey(),
        null,
        [
            'room_type' => 'living_room',
            'confidence' => 0.78,
            'measurement_quality' => 'Standart iç kapı boyutları ve dört fotoğrafın birlikte değerlendirilmesine dayalı, düşük güvenli yaklaşık ölçülendirme.',
            'estimated_dimensions' => ['width_mm' => 3_900, 'length_mm' => 5_200, 'height_mm' => 2_700],
        ],
        [(string) $photo->getKey()],
    );

    /*
     * The reading survives and the column holds nothing, because the first twenty characters
     * of a sentence is not a category — it is a category nobody can look up. The sentence
     * itself is not lost: the whole answer goes into the payload untouched.
     */
    expect($analysis->exists)->toBeTrue()
        ->and($analysis->measurement_quality)->toBeNull()
        ->and($analysis->detected_room_type)->toBe('living_room')
        ->and($analysis->payload['measurement_quality'])->toStartWith('Standart iç kapı');
});

it('keeps the measurement quality when the reading answers with one of the words', function (): void {
    $photo = ($this->photo)(0);

    $analysis = app(RoomAnalyser::class)->store(
        $this->room,
        (string) $photo->getKey(),
        null,
        ['room_type' => 'living_room', 'measurement_quality' => 'Estimated'],
        [(string) $photo->getKey()],
    );

    // Case folded, because a model that shouts is still answering the question.
    expect($analysis->measurement_quality)->toBe('estimated');
});

it('keeps a reading whose room type came back as a description', function (): void {
    $photo = ($this->photo)(0);

    $analysis = app(RoomAnalyser::class)->store(
        $this->room,
        (string) $photo->getKey(),
        null,
        ['room_type' => 'oturma odası ve yemek alanı birleşik, geçiş holüne açılan salon'],
        [(string) $photo->getKey()],
    );

    // Forty characters is what the column holds. A room type is free text rather than an
    // enum, so the front of an expansive answer is still a room type; a paragraph is not.
    expect($analysis->exists)->toBeTrue()
        ->and(mb_strlen((string) $analysis->detected_room_type))->toBeLessThanOrEqual(40);
});

/*
 * --- which photographs, not in which order ---------------------------------
 *
 * The reading goes to the model with the chosen frame first, because that is the one the
 * design is drawn from. Picking a different frame therefore reorders it — and staleness was
 * decided by comparing the two lists position by position, so a finished reading of four
 * photographs looked like a reading of some other room the moment the customer chose which
 * one to draw from.
 *
 * What they saw was "4 kareyi aldım, odanı okuyorum" and nothing after it. The reading had
 * landed in fifty-five seconds, hours earlier, and was sitting in the table the whole time.
 * They waited, pressed the button again, paid for two more readings of a room that had been
 * read three times, and finally asked why it was so slow. It was not slow.
 */

it('does not go stale because the customer chose a different frame to draw from', function (): void {
    $photos = [($this->photo)(0), ($this->photo)(1), ($this->photo)(2), ($this->photo)(3)];
    $this->room->forceFill(['primary_media_id' => $photos[0]->getKey()])->save();

    app(RoomAnalyser::class)->forRoom($this->room->fresh(), refresh: true);

    expect($this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.is_stale'))->toBeFalse();

    // "Tasarımı bundan çiziyorum" on the last frame. The same four pictures, read together.
    $this->room->forceFill(['primary_media_id' => $photos[3]->getKey()])->save();

    expect($this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.is_stale'))->toBeFalse();

    // And nothing is read again, so nothing is charged again.
    Queue::fake();
    $this->actingAs($this->owner)->postJson("{$this->url}/analyse")->assertOk()->assertJsonPath('data.status', 'ready');
    Queue::assertNothingPushed();
});

it('still goes stale when a photograph is actually added', function (): void {
    ($this->photo)(0);
    ($this->photo)(1);

    app(RoomAnalyser::class)->forRoom($this->room->fresh(), refresh: true);

    expect($this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.is_stale'))->toBeFalse();

    ($this->photo)(2);

    expect($this->actingAs($this->owner)->getJson($this->url)->json('data.analysis.is_stale'))->toBeTrue();
});

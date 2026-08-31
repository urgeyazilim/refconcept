<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Jobs\GenerateDesignVideo;
use App\Domains\Projects\Models\DesignAsset;
use App\Domains\Projects\Models\DesignVideo;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\DesignVideoLauncher;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Filming a finished design, and what it costs when it does not work.
 *
 * A film is the most expensive thing a customer can ask this platform for — about three
 * premium renders — so almost everything worth asserting here is about money and about
 * refusals. Whether the video looks good is not something a test can know; whether somebody
 * was charged twice for one button, or charged at all for a film that never arrived, is.
 *
 * The fake provider answers deterministically and returns a genuine `video/mp4` file, so
 * the whole path runs — including the part that files it by extension, which is where this
 * feature's one real bug lived.
 */
/** Somebody who may spend on this project but is not paying for it out of the owner's purse. */
function makeEditor(Project $project, User $user): void
{
    $member = $project->members()->create([
        'invited_email' => $user->email,
        'user_id' => $user->getKey(),
        'role' => 'editor',
    ]);

    $member->forceFill(['status' => 'active', 'accepted_at' => now()])->save();
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();
    Storage::fake('s3');

    /*
     * The queue is faked so "held" can be observed.
     *
     * Under the sync driver the launcher's dispatch runs the whole film before `launch()`
     * has even returned, so every assertion about a reservation was reading a wallet the
     * job had already settled. The tests that want the work done run it by hand.
     */
    Queue::fake();

    $this->run = fn (DesignVideo $video) => (new GenerateDesignVideo((string) $video->getKey()))->handle(
        app(AiJobDispatcher::class),
        $this->launcher,
        app(RoomPhotoStorage::class),
        $this->ledger,
    );

    $this->ledger = app(CreditLedger::class);
    $this->launcher = app(DesignVideoLauncher::class);

    $this->owner = User::factory()->create();
    $this->ledger->grant($this->owner, 100, CreditLotSource::Purchase, 'Test paketi');

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    $this->design = $this->room->designs()->create([
        'name' => 'Salon tasarımı',
        'created_by' => $this->owner->getKey(),
    ]);

    $this->version = $this->design->versions()->create([
        'version_number' => 1,
        'created_by' => $this->owner->getKey(),
    ]);

    // Status is not fillable on purpose — the tree owns it — so a finished version is
    // arranged the way the pipeline arrives at one rather than mass-assigned into place.
    $this->version->forceFill([
        'status' => DesignVersionStatus::Ready,
        'completed_at' => now(),
    ])->save();

    // The still the camera moves through. Without one there is nothing to film, and the
    // launcher says so rather than paying a provider to invent a room.
    $this->render = DesignAsset::query()->create([
        'design_version_id' => $this->version->getKey(),
        'type' => 'render',
        'disk' => 's3',
        'storage_path' => 'design-assets/'.$this->version->getKey().'/'.Str::uuid7().'.png',
        'mime_type' => 'image/png',
        'size_bytes' => 120_000,
        'width' => 1_536,
        'height' => 1_024,
        'checksum_sha256' => hash('sha256', 'render'),
    ]);

    makeAiRoute(AiTask::VideoTour, ['credit_cost' => 20, 'max_attempts' => 1]);
});

it('holds the credits before a worker ever sees the job', function (): void {
    $video = $this->launcher->launch($this->version, $this->owner);

    expect($video->status)->toBe(DesignVersionStatus::Pending)
        ->and($video->credit_cost)->toBe(20)
        ->and($video->credit_reservation_id)->not->toBeNull();

    // Held, not spent: the balance still counts them, and the available figure does not.
    $wallet = $this->ledger->balanceFor($this->owner);

    expect($wallet->reserved)->toBe(20)
        ->and($wallet->available())->toBe(80);
});

it('films the design and keeps the file as a video', function (): void {
    $video = $this->launcher->launch($this->version, $this->owner);

    ($this->run)($video);

    $video->refresh();

    expect($video->status)->toBe(DesignVersionStatus::Ready)
        ->and($video->asset_id)->not->toBeNull();

    $asset = $video->asset;

    /*
     * The bug this assertion exists for. The staging store keeps only the extension, so a
     * film that fell through to the default was filed as `image/png` — stored with that
     * content type and served with it, which makes a browser download fifteen megabytes
     * instead of playing them. Nothing errors; the video simply never appears.
     */
    expect($asset?->type)->toBe('video')
        ->and($asset?->mime_type)->toBe('video/mp4')
        ->and($asset?->storage_path)->toEndWith('.mp4');
});

it('takes the credits once the film exists', function (): void {
    $video = $this->launcher->launch($this->version, $this->owner);

    ($this->run)($video);

    $wallet = $this->ledger->balanceFor($this->owner);

    expect($wallet->balance)->toBe(80)
        ->and($wallet->reserved)->toBe(0);
});

it('gives the credits back when the provider fails', function (): void {
    FakeAiProvider::scriptFailure(AiFailureKind::ProviderError, 'Sağlayıcı hatası');

    $video = $this->launcher->launch($this->version, $this->owner);

    ($this->run)($video);

    $video->refresh();

    expect($video->status)->toBe(DesignVersionStatus::Failed)
        ->and($video->failure_reason)->not->toBeNull();

    // A film that was not made costs nothing. Not "is refunded later" — the hold is
    // released, so the customer can spend the credits again immediately.
    $wallet = $this->ledger->balanceFor($this->owner);

    expect($wallet->balance)->toBe(100)
        ->and($wallet->reserved)->toBe(0);
});

it('refuses to film a design that is not finished', function (): void {
    $this->version->forceFill(['status' => DesignVersionStatus::Generating])->save();

    expect(fn () => $this->launcher->launch($this->version->refresh(), $this->owner))
        ->toThrow(DesignVersionRefused::class);

    expect(DesignVideo::query()->count())->toBe(0)
        ->and($this->ledger->balanceFor($this->owner)->reserved)->toBe(0);
});

it('refuses a second film while one is still being made', function (): void {
    $this->launcher->launch($this->version, $this->owner);

    // Two clicks a moment apart on a slow page is the ordinary way somebody would pay
    // twice for one video.
    expect(fn () => $this->launcher->launch($this->version, $this->owner))
        ->toThrow(DesignVersionRefused::class);

    expect(DesignVideo::query()->count())->toBe(1)
        ->and($this->ledger->balanceFor($this->owner)->reserved)->toBe(20);
});

it('allows another film once the first has finished', function (): void {
    $first = $this->launcher->launch($this->version, $this->owner);

    $first->forceFill(['status' => DesignVersionStatus::Ready, 'completed_at' => now()])->save();

    // Not one film ever: a customer who did not like the camera move should be able to pay
    // for another rather than lose the first.
    $second = $this->launcher->launch($this->version, $this->owner);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and(DesignVideo::query()->count())->toBe(2);
});

it('refuses when the customer cannot pay for it', function (): void {
    $poor = User::factory()->create();
    makeEditor($this->project, $poor);

    expect(fn () => $this->launcher->launch($this->version, $poor))
        ->toThrow(InsufficientCredits::class);

    // Marked failed rather than deleted, so the customer sees why on the design they were
    // looking at instead of finding that their click did nothing at all.
    $video = DesignVideo::query()->firstOrFail();

    expect($video->status)->toBe(DesignVersionStatus::Failed)
        ->and($video->failure_reason)->not->toBeNull();
});

it('lets the owner start a film over HTTP and read it back', function (): void {
    $base = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}"
        ."/designs/{$this->design->getKey()}/versions/{$this->version->getKey()}";

    $this->actingAs($this->owner)
        ->postJson("{$base}/video")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.credit_cost', 20);

    $this->actingAs($this->owner)
        ->getJson("{$base}/videos")
        ->assertOk()
        ->assertJsonPath('credit_cost', 20)
        ->assertJsonCount(1, 'data');
});

it('will not let a stranger film somebody elses room', function (): void {
    $stranger = User::factory()->create();

    $base = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}"
        ."/designs/{$this->design->getKey()}/versions/{$this->version->getKey()}";

    $this->actingAs($stranger)->postJson("{$base}/video")->assertForbidden();
    $this->actingAs($stranger)->getJson("{$base}/videos")->assertForbidden();

    expect(DesignVideo::query()->count())->toBe(0);
});

it('answers 402 rather than 422 when the balance is short', function (): void {
    $broke = User::factory()->create();
    makeEditor($this->project, $broke);

    $base = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}"
        ."/designs/{$this->design->getKey()}/versions/{$this->version->getKey()}";

    // The request was well formed and the customer is allowed to make it; they cannot pay
    // for it. The client shows a top-up prompt, not a form error.
    $this->actingAs($broke)
        ->postJson("{$base}/video")
        ->assertStatus(402)
        ->assertJsonPath('required_credits', 20);
});

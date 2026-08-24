<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Jobs\RunAiJob;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiGateway;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Who can see what a customer asked for.
 *
 * A job's input is the link to a photograph of somebody's living room and whatever they
 * typed about how they live in it. The rule is the same one drawn for projects — the
 * customer, and nobody else, including a super admin — and it is asserted here
 * separately because a job is a second door into the same room. A test that only covered
 * projects would have let this one stay open.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();

    $this->admin = User::factory()->create();
    grantPlatformRole($this->admin, SystemRole::SuperAdmin);

    makeAiRoute(AiTask::RoomAnalysis);

    $this->job = app(AiGateway::class)->run(makeAiJob(
        AiTask::RoomAnalysis,
        [
            'room_type' => 'salon',
            'notes' => 'Kızımın odası, pencerenin önü boş kalsın.',
            'image_urls' => ['https://storage.test/private/oda.jpg'],
        ],
        $this->owner,
    ));
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('lets the owner read their own job', function (): void {
    $this->actingAs($this->owner)
        ->getJson("/api/v1/ai/jobs/{$this->job->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.status', AiJobStatus::Succeeded->value)
        ->assertJsonPath('data.is_finished', true)
        // Never cached: this is the endpoint a client polls precisely because the answer
        // changes, and a proxy holding the queued reply would freeze a finished render.
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses a stranger', function (): void {
    $this->actingAs($this->stranger)
        ->getJson("/api/v1/ai/jobs/{$this->job->getKey()}")
        ->assertForbidden();
});

it('refuses a super admin, who has no business reading it', function (): void {
    /*
     * The one blanket override in the system is deliberately not blanket over this. If a
     * genuine support need appears, the answer is an audited, time-boxed, customer-
     * consented grant — not a role that quietly reads everybody's rooms.
     */
    $this->actingAs($this->admin)
        ->getJson("/api/v1/ai/jobs/{$this->job->getKey()}")
        ->assertForbidden();
});

it('shows platform staff the operational record without the payload', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/ai/jobs/{$this->job->getKey()}")
        ->assertOk();

    $body = json_encode($response->json());

    // Everything an operator needs to answer "are renders failing this morning"...
    expect($response->json('data.task'))->toBe(AiTask::RoomAnalysis->value)
        ->and($response->json('data.attempts'))->toHaveCount(1)
        ->and($response->json('data.status'))->toBe(AiJobStatus::Succeeded->value);

    // ...and nothing that describes anybody's home.
    expect($body)->not->toContain('Kızımın odası')
        ->and($body)->not->toContain('oda.jpg')
        ->and($response->json('data.input'))->toBeNull()
        ->and($response->json('data.output'))->toBeNull();
});

it('keeps the customer view free of provider and model detail', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson("/api/v1/ai/jobs/{$this->job->getKey()}")
        ->assertOk();

    /*
     * Which model produced an answer is of no use to a customer and of considerable use
     * to a competitor, and this endpoint is reachable by anybody with an account.
     */
    $body = json_encode($response->json());

    expect($body)->not->toContain('fake-primary')
        ->and($response->json('data.cost_micros'))->toBeNull()
        ->and($response->json('data.attempts'))->toBeNull();
});

it('lets the owner cancel unfinished work and says so plainly when there is none', function (): void {
    $queued = makeAiJob(AiTask::RoomAnalysis, ['room_type' => 'yatak odası'], $this->owner);

    $this->actingAs($this->owner)
        ->postJson("/api/v1/ai/jobs/{$queued->getKey()}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', AiJobStatus::Cancelled->value)
        ->assertJsonPath('message', 'İşlem iptal edildi.');

    // A finished job cannot be cancelled, and saying so is not pedantry: the credit is
    // already spent, and a message claiming otherwise would be a lie.
    $this->actingAs($this->owner)
        ->postJson("/api/v1/ai/jobs/{$this->job->getKey()}/cancel")
        ->assertForbidden();
});

it('returns the same job for a repeated idempotency key instead of charging twice', function (): void {
    $dispatcher = app(AiJobDispatcher::class);

    $first = $dispatcher->dispatch(
        AiTask::RoomAnalysis,
        ['room_type' => 'salon'],
        $this->owner,
        idempotencyKey: 'render-tap-1',
    );

    $second = $dispatcher->dispatch(
        AiTask::RoomAnalysis,
        ['room_type' => 'salon'],
        $this->owner,
        idempotencyKey: 'render-tap-1',
    );

    // A customer who taps twice, or a client that retries a request whose response it
    // never saw, must not pay twice. The second tap should look like the first worked.
    expect($second->getKey())->toBe($first->getKey())
        ->and(AiJob::query()->where('idempotency_key', 'render-tap-1')->count())->toBe(1);
});

it('refuses a new job once the caller is at their concurrency limit', function (): void {
    /*
     * The queue is faked so the jobs stay in flight. Without it the sync driver runs
     * each one to completion the instant it is dispatched, and a limit on concurrent
     * work is untestable against work that is never concurrent.
     */
    Queue::fake();

    makeAiRoute(AiTask::ImageRenderDraft, ['max_concurrency' => 1]);

    $dispatcher = app(AiJobDispatcher::class);

    $dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'ilk'], $this->owner);

    // Per user, not global: one person queueing forty renders must not put everybody
    // else behind them, and must not lock everybody else out either.
    $dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'başkası'], $this->stranger);

    expect(fn () => $dispatcher->dispatch(AiTask::ImageRenderDraft, ['prompt' => 'ikinci'], $this->owner))
        ->toThrow(AiJobRefused::class);

    // The two that were accepted did reach the worker; a limit that also swallowed
    // the dispatch would look identical from the caller and never run anything.
    Queue::assertPushed(RunAiJob::class, 2);
});

it('refuses to queue anything for a paused task', function (): void {
    [$route] = makeAiRoute(AiTask::ImageRenderPremium);

    $route->forceFill(['is_paused' => true, 'pause_reason' => 'Bakım çalışması.'])->save();

    /*
     * Refused at the door rather than queued and failed. A queue full of jobs that will
     * all fail identically is a backlog somebody has to clear afterwards, and a customer
     * given a job id to poll learns nothing they could not have been told immediately.
     */
    try {
        app(AiJobDispatcher::class)->dispatch(AiTask::ImageRenderPremium, ['prompt' => 'x'], $this->owner);

        expect(false)->toBeTrue('Duraklatılmış görev kabul edildi.');
    } catch (AiJobRefused $e) {
        expect($e->status)->toBe(503)
            ->and($e->getMessage())->toBe('Bakım çalışması.');
    }

    expect(AiJob::query()->where('task', AiTask::ImageRenderPremium->value)->exists())
        ->toBeFalse();
});

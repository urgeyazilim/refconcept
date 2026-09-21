<?php

declare(strict_types=1);

use App\Domains\Ai\Contracts\AiProvider;
use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiCostRate;
use App\Domains\Ai\Models\AiFailure;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider as AiProviderModel;
use App\Domains\Ai\Models\AiRequest;
use App\Domains\Ai\Models\AiUsage;
use App\Domains\Ai\Models\PromptTemplate;
use App\Domains\Ai\Models\PromptVersion;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\AiGateway;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Project;

/**
 * The gateway's policy, exercised against a provider that cannot surprise it.
 *
 * Every one of these is a decision that would otherwise only be observable in
 * production, at the worst moment: a retry loop that retries something that will never
 * work, a fallback that never fires, a cost ceiling checked after the money is spent.
 * The fake provider exists so each can be provoked on demand and asserted exactly.
 *
 * What is *not* tested here is that a real provider behaves as its adapter assumes. That
 * is not knowable from a test suite, and pretending otherwise with a recorded fixture
 * would only assert that the fixture still matches itself.
 */
beforeEach(function (): void {
    FakeAiProvider::reset();

    $this->gateway = app(AiGateway::class);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('runs a job through the routed model and records the attempt', function (): void {
    [$route, $model] = makeAiRoute(AiTask::SupportAssist);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist, ['prompt' => 'Kargom nerede?']));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->attempts)->toBe(1)
        ->and($job->output['text'] ?? null)->toContain('support_assist')
        ->and($job->route_id)->toBe($route->getKey())
        /*
         * The gateway records which route ran the job and deliberately does not touch its
         * cost. Who pays, and how much, is settled when the job is accepted — the design
         * pipeline runs its three model calls at zero because the version above them holds
         * the whole price, and a gateway that re-read the route here would charge for
         * those steps a second time.
         */
        ->and($job->credit_cost)->toBe(0);

    $request = AiRequest::query()->where('job_id', $job->getKey())->firstOrFail();

    expect($request->attempt)->toBe(1)
        ->and($request->is_fallback)->toBeFalse()
        ->and($request->model_id)->toBe($model->getKey())
        ->and($request->status)->toBe('succeeded');

    // Usage is written even though this model has no rate on file: the tokens are a fact
    // whether or not somebody has filled in a price list.
    expect(AiUsage::query()->where('job_id', $job->getKey())->count())->toBe(1);
});

it('refuses a task with no route without contacting a provider', function (): void {
    $job = $this->gateway->run(makeAiJob(AiTask::RoomAnalysis));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::NoRouteConfigured)
        ->and($job->attempts)->toBe(0)
        ->and(FakeAiProvider::callCount())->toBe(0);

    /*
     * The failure row matters as much as the status. Without it, "no route configured"
     * is invisible on the dashboard that exists to show why jobs are failing — which is
     * exactly the screen somebody is looking at when a feature has never worked.
     */
    $failure = AiFailure::query()->where('job_id', $job->getKey())->firstOrFail();

    expect($failure->attempt)->toBe(0)
        ->and($failure->request_id)->toBeNull()
        ->and($failure->was_retryable)->toBeFalse();
});

it('honours the kill switch before spending anything', function (): void {
    [$route] = makeAiRoute(AiTask::SupportAssist);

    $route->forceFill(['is_paused' => true, 'pause_reason' => 'Sağlayıcı kesintisi.'])->save();

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::KillSwitchEngaged)
        // The operator's words reach the record, so the next person knows why.
        ->and($job->failure_reason)->toBe('Sağlayıcı kesintisi.')
        ->and(FakeAiProvider::callCount())->toBe(0);
});

it('retries a transient failure on the same model', function (): void {
    makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 3]);

    FakeAiProvider::scriptFailure(AiFailureKind::Timeout);
    FakeAiProvider::scriptFailure(AiFailureKind::Timeout);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->attempts)->toBe(3)
        ->and(FakeAiProvider::callCount())->toBe(3);

    // Every attempt is on the record, including the two that failed: a provider that
    // read the input and then timed out still charged for reading it.
    expect(AiRequest::query()->where('job_id', $job->getKey())->count())->toBe(3)
        ->and(AiFailure::query()->where('job_id', $job->getKey())->count())->toBe(2);
});

it('does not retry a failure that would repeat identically', function (): void {
    makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 3]);

    // An invalid request is our mistake, not the provider's mood. Trying it twice more
    // would spend ten seconds of a customer's patience arriving at the same place.
    FakeAiProvider::scriptFailure(AiFailureKind::InvalidRequest, 'Model kodu tanınmadı.');

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::InvalidRequest)
        ->and(FakeAiProvider::callCount())->toBe(1);
});

it('falls back to the second model when the first keeps failing', function (): void {
    [$route, $primary] = makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 2], withFallback: true);

    FakeAiProvider::scriptFailure(AiFailureKind::ProviderError);
    FakeAiProvider::scriptFailure(AiFailureKind::ProviderError);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->attempts)->toBe(3);

    $attempts = AiRequest::query()->where('job_id', $job->getKey())->orderBy('attempt')->get();

    expect($attempts->take(2)->pluck('model_id')->unique()->all())->toBe([$primary->getKey()])
        ->and($attempts->last()->model_id)->toBe($route->fallback_model_id)
        ->and($attempts->last()->is_fallback)->toBeTrue();
});

it('moves straight to the fallback when a provider refuses on safety grounds', function (): void {
    [$route] = makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 3], withFallback: true);

    /*
     * A safety refusal is not retryable — the same provider will refuse identically —
     * but it *does* warrant a fallback, because providers draw the line in different
     * places and one refusing to describe a bedroom is a provider problem rather than a
     * request problem.
     */
    FakeAiProvider::scriptFailure(AiFailureKind::SafetyRefusal);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->attempts)->toBe(2);

    $second = AiRequest::query()->where('job_id', $job->getKey())->where('attempt', 2)->firstOrFail();

    expect($second->is_fallback)->toBeTrue()
        ->and($second->model_id)->toBe($route->fallback_model_id);
});

it('does not try the fallback when the failure would follow it there', function (): void {
    makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 2], withFallback: true);

    FakeAiProvider::scriptFailure(AiFailureKind::InvalidRequest);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Failed)
        // One call, total. A malformed request is malformed for everybody.
        ->and(FakeAiProvider::callCount())->toBe(1);
});

it('refuses a call whose estimate exceeds the route ceiling, before making it', function (): void {
    [$route, $model] = makeAiRoute(AiTask::SupportAssist, ['max_cost_micros' => 10]);

    // An expensive model: one million micros per million output tokens, and the model is
    // allowed a thousand of them — a thousand micros against a ceiling of ten.
    AiCostRate::query()->create([
        'model_id' => $model->getKey(),
        'currency' => 'USD',
        'input_micros_per_million_tokens' => 1_000_000,
        'output_micros_per_million_tokens' => 1_000_000,
        'micros_per_image' => 0,
        'micros_per_request' => 0,
        'effective_from' => now()->subDay(),
    ]);

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::CostCapExceeded)
        // The whole point of a ceiling is that nothing is spent reaching it.
        ->and(FakeAiProvider::callCount())->toBe(0)
        ->and($job->failure_reason)->toContain((string) $route->max_cost_micros);
});

it('prices an attempt from the rate table rather than from the provider', function (): void {
    [, $model] = makeAiRoute(AiTask::SupportAssist);

    AiCostRate::query()->create([
        'model_id' => $model->getKey(),
        'currency' => 'TRY',
        'input_micros_per_million_tokens' => 2_000_000,
        'output_micros_per_million_tokens' => 4_000_000,
        'micros_per_image' => 0,
        'micros_per_request' => 100,
        'effective_from' => now()->subDay(),
    ]);

    FakeAiProvider::script(AiResult::success(
        text: 'ok',
        inputTokens: 1_000_000,
        outputTokens: 500_000,
    ));

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    $usage = AiUsage::query()->where('job_id', $job->getKey())->firstOrFail();

    // 100 fixed + 1M×2 + 0.5M×4 = 100 + 2,000,000 + 2,000,000.
    expect($usage->cost_micros)->toBe(4_000_100)
        ->and($usage->currency)->toBe('TRY')
        ->and($job->total_cost_micros)->toBe(4_000_100)
        // Credits are charged once for the job, never per attempt.
        ->and($usage->credits_charged)->toBe(0);
});

it('treats an answer that is not the requested JSON as a failure worth retrying', function (): void {
    $version = publishPrompt(AiTask::RoomAnalysis, [
        'required' => ['room_type'],
        'properties' => ['room_type' => ['type' => 'string']],
    ]);

    makeAiRoute(AiTask::RoomAnalysis, [
        'max_attempts' => 2,
        'prompt_version_id' => $version->getKey(),
    ]);

    // Prose where an object was asked for: the commonest real failure with structured
    // output, and the reason the validator sits in the gateway rather than downstream.
    FakeAiProvider::scriptMalformed();

    $job = $this->gateway->run(makeAiJob(AiTask::RoomAnalysis));

    expect($job->status)->toBe(AiJobStatus::Succeeded)
        ->and($job->attempts)->toBe(2);

    $failure = AiFailure::query()->where('job_id', $job->getKey())->firstOrFail();

    expect($failure->kind)->toBe(AiFailureKind::MalformedOutput)
        ->and($failure->was_retryable)->toBeTrue();
});

it('fails a structured task whose answer is missing a key the application reads', function (): void {
    $version = publishPrompt(AiTask::RoomAnalysis, [
        'required' => ['floor_area_m2'],
        'properties' => ['floor_area_m2' => ['type' => 'number']],
    ]);

    makeAiRoute(AiTask::RoomAnalysis, [
        'max_attempts' => 1,
        'prompt_version_id' => $version->getKey(),
    ]);

    $job = $this->gateway->run(makeAiJob(AiTask::RoomAnalysis));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::MalformedOutput)
        // Named, so the person reading the failure knows which key was missing.
        ->and($job->failure_reason)->toContain('floor_area_m2');
});

it('sends the routed prompt version, rendered with the job input', function (): void {
    $version = publishPrompt(AiTask::SupportAssist, null, 'Müşteri sordu: {{ question }}', 'Kısa yanıt ver.');

    makeAiRoute(AiTask::SupportAssist, ['prompt_version_id' => $version->getKey()]);

    $this->gateway->run(makeAiJob(AiTask::SupportAssist, ['question' => 'Kargom nerede?']));

    $call = FakeAiProvider::lastCall();

    expect($call?->prompt)->toBe('Müşteri sordu: Kargom nerede?')
        ->and($call?->systemPrompt)->toBe('Kısa yanıt ver.');
});

it('never puts an image url into the prompt text', function (): void {
    $version = publishPrompt(AiTask::RoomAnalysis, null, 'Oda: {{ room_type }} / {{ image_urls }}');

    makeAiRoute(AiTask::RoomAnalysis, ['prompt_version_id' => $version->getKey()]);

    $this->gateway->run(makeAiJob(AiTask::RoomAnalysis, [
        'room_type' => 'salon',
        'image_urls' => ['https://storage.test/private/oda-fotograf.jpg'],
    ]));

    $call = FakeAiProvider::lastCall();

    /*
     * The link travels as an attachment, never as text. A URL pasted into a prompt is a
     * URL a model can repeat back inside an answer that somebody else reads — and this
     * one points at a photograph of a customer's home.
     */
    expect($call?->prompt)->not->toContain('oda-fotograf.jpg')
        ->and($call?->prompt)->toContain('{{ image_urls }}')
        ->and($call?->imageUrls)->toBe(['https://storage.test/private/oda-fotograf.jpg']);
});

it('keeps the api key out of a call fingerprint', function (): void {
    makeAiRoute(AiTask::SupportAssist);

    $this->gateway->run(makeAiJob(AiTask::SupportAssist, ['prompt' => 'aynı istem']));
    $first = FakeAiProvider::lastCall()?->fingerprint();

    FakeAiProvider::reset();

    $this->gateway->run(makeAiJob(AiTask::SupportAssist, ['prompt' => 'aynı istem']));

    expect(FakeAiProvider::lastCall()?->fingerprint())->toBe($first);
});

it('survives an adapter that throws instead of returning a failure', function (): void {
    makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 1]);

    // Adapters are not supposed to throw. One that does is a bug in the adapter, and a
    // customer watching a spinner forever is a worse outcome than a wrong label.
    app()->bind(FakeAiProvider::class, fn () => new class extends stdClass implements AiProvider
    {
        public function driver(): string
        {
            return 'fake';
        }

        public function supports(AiCall $call): bool
        {
            return true;
        }

        public function execute(AiCall $call): AiResult
        {
            throw new RuntimeException('boom');
        }
    });

    $job = $this->gateway->run(makeAiJob(AiTask::SupportAssist));

    expect($job->status)->toBe(AiJobStatus::Failed)
        ->and($job->failure_kind)->toBe(AiFailureKind::ProviderError)
        ->and($job->failure_reason)->toContain('boom')
        ->and($job->finished_at)->not->toBeNull();
});

it('counts only unfinished work towards a user concurrency limit', function (): void {
    makeAiRoute(AiTask::ImageRenderDraft);

    $user = User::factory()->create();

    makeAiJob(AiTask::ImageRenderDraft, [], $user);
    $finished = makeAiJob(AiTask::ImageRenderDraft, [], $user);
    // The schema refuses a succeeded job with no output and no finish time, which is
    // the constraint that stops a half-written job from looking complete.
    $finished->forceFill([
        'status' => AiJobStatus::Succeeded,
        'output' => ['text' => 'ok'],
        'finished_at' => now(),
    ])->save();

    // Somebody else's queue is not this user's problem, and a finished job is not in
    // flight — a limit that counted either would lock people out for no reason.
    makeAiJob(AiTask::ImageRenderDraft, [], User::factory()->create());

    expect($this->gateway->inFlightFor((string) $user->getKey(), AiTask::ImageRenderDraft))->toBe(1);
});

/**
 * Publishes a prompt version for a task, the way the seeder does.
 *
 * @param  array<string, mixed>|null  $schema
 */
function publishPrompt(
    AiTask $task,
    ?array $schema = null,
    string $template = 'Test şablonu',
    ?string $system = null,
): PromptVersion {
    $promptTemplate = PromptTemplate::query()->create([
        'code' => $task->value.'-'.uniqid(),
        'name' => $task->label(),
        'task' => $task,
    ]);

    $version = PromptVersion::query()->create([
        'template_id' => $promptTemplate->getKey(),
        'version' => 1,
        'user_template' => $template,
        'system_prompt' => $system,
        'response_schema' => $schema,
        'change_note' => 'Test.',
    ]);

    $version->forceFill(['status' => 'published', 'published_at' => now()])->save();

    return $version;
}

it('never lets the simulator answer for a real model that failed', function (): void {
    [$route] = makeAiRoute(AiTask::SupportAssist, ['max_attempts' => 1], withFallback: true);

    // Two simulator models: a development route with no key on file. Both may answer.
    expect($route->fresh()?->candidateModels())->toHaveCount(2);

    $google = AiProviderModel::query()->create(['code' => 'google-test', 'name' => 'Google', 'driver' => 'google', 'is_active' => true]);
    $google->credentials()->create(['label' => 'test', 'secret_encrypted' => 'g-0000000000', 'secret_hint' => '0000', 'is_active' => true]);

    $real = AiModel::query()->create([
        'provider_id' => $google->getKey(),
        'code' => 'gemini-test',
        'name' => 'Gemini test',
        'modality' => AiTask::SupportAssist->modality(),
        'max_output_tokens' => 1_000,
        'supports_structured_output' => true,
        'supports_image_input' => true,
        'is_active' => true,
    ]);

    $route->forceFill(['primary_model_id' => $real->getKey()])->save();

    /*
     * A real primary with the simulator as its fallback is a development convenience, not
     * a route. When the real model fails three times the customer must get a failure, not
     * the simulator's stock answer presented as a reading of their own room — which is
     * what happened, once, to a real living room.
     */
    $models = $route->fresh()?->candidateModels() ?? [];

    expect($models)->toHaveCount(1)
        ->and($models[0]->getKey())->toBe($real->getKey());
});

describe('an account that is being tested with', function (): void {
    /*
     * The suite used to point the whole platform's routes at the simulator for the length of a
     * run and put them back afterwards. Anybody using the site during a run got a fake answer,
     * and the product owner did: six photographs of their living room came back as the
     * simulator's canned living room in zero seconds. It is also how a run once billed real
     * providers, when the restore ran before the last queued job finished.
     */
    beforeEach(function (): void {
        config()->set('refconcept.simulated_email_domain', 'e2e.refconcept.local');
    });

    it('is answered by the simulator without anything global moving', function (): void {
        [$route, $model] = makeAiRoute(AiTask::SupportAssist);

        $tester = User::factory()->create(['email' => 'walkthrough-1@e2e.refconcept.local']);
        $resolved = $this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist, [], $tester));

        expect($resolved?->primaryModel?->provider?->driver)->toBe('fake')
            // The row on disk is untouched: the next person through gets the real model.
            ->and($route->fresh()->primary_model_id)->toBe($model->getKey());
    });

    it('leaves everybody else on the model the operator chose', function (): void {
        [, $model] = makeAiRoute(AiTask::SupportAssist);

        $customer = User::factory()->create(['email' => 'musteri@example.com']);
        $resolved = $this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist, [], $customer));

        expect($resolved?->primaryModel?->getKey())->toBe($model->getKey());
    });

    it('leaves a job with nobody behind it alone', function (): void {
        [, $model] = makeAiRoute(AiTask::SupportAssist);

        // A catalogue job, a backfill, anything the platform runs for itself.
        $resolved = $this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist));

        expect($resolved?->primaryModel?->getKey())->toBe($model->getKey());
    });

    /*
     * Every step of a design runs inside a queued job that nobody pressed a button for, so
     * those jobs carry no user. Treating them as nobody's work is what forced the end-to-end
     * suite to repoint the whole platform instead of one account, and a run that died halfway
     * then left the site answering from the simulator without saying so.
     */
    it('follows a design version back to the person whose room it is', function (): void {
        makeAiRoute(AiTask::SupportAssist);

        $tester = User::factory()->create(['email' => 'walkthrough-3@e2e.refconcept.local']);

        expect($this->gateway->resolveRoute(jobAbout(versionOwnedBy($tester)))?->primaryModel?->provider?->driver)
            ->toBe('fake');
    });

    it("leaves a customer's own design on the model the operator chose", function (): void {
        [, $model] = makeAiRoute(AiTask::SupportAssist);

        $customer = User::factory()->create(['email' => 'musteri-2@example.com']);

        expect($this->gateway->resolveRoute(jobAbout(versionOwnedBy($customer)))?->primaryModel?->getKey())
            ->toBe($model->getKey());
    });

    it('does nothing at all when no domain is configured', function (): void {
        config()->set('refconcept.simulated_email_domain', '');

        [, $model] = makeAiRoute(AiTask::SupportAssist);

        $tester = User::factory()->create(['email' => 'walkthrough-2@e2e.refconcept.local']);
        $resolved = $this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist, [], $tester));

        // Which is production: the switch is off unless somebody turns it on.
        expect($resolved?->primaryModel?->getKey())->toBe($model->getKey());
    });
});

/*
 * --- the simulator is not reachable by accident ----------------------------
 *
 * Everything above is about choosing the simulator on purpose. This is about the routing
 * table naming it when nobody asked, which is how a real customer met it: an end-to-end run
 * wrote the simulator into the shared routes and died before putting them back, and for six
 * hours the running installation answered every reading from it. The product owner
 * photographed their living room, waited, was handed a canned living room in zero seconds,
 * and asked whether the system was working at all.
 *
 * The test suite is the one place such a route is legitimate — every test in this file is
 * one — so it says so in its configuration, and these two turn that off to stand where
 * production stands.
 */

it('refuses a route that names the simulator when nobody asked for it', function (): void {
    config()->set('refconcept.simulator_in_routing_table', false);

    makeAiRoute(AiTask::SupportAssist);

    $customer = User::factory()->create(['email' => 'musteri-3@example.com']);

    /*
     * No route rather than a repaired one. "Bu görev için yönlendirme yok" is something an
     * operator can act on within a minute; a room invented out of nothing and handed to
     * somebody as a reading of their own is something only the customer finds out about.
     */
    expect($this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist, [], $customer)))->toBeNull();
});

it('still hands the simulator to the account being tested with', function (): void {
    config()->set('refconcept.simulator_in_routing_table', false);
    config()->set('refconcept.simulated_email_domain', 'e2e.refconcept.local');

    makeAiRoute(AiTask::SupportAssist);

    // The fence is around the table, not around the simulator: asking by account still works,
    // which is what lets the end-to-end suite run without writing anything global down.
    $tester = User::factory()->create(['email' => 'walkthrough-4@e2e.refconcept.local']);

    expect($this->gateway->resolveRoute(makeAiJob(AiTask::SupportAssist, [], $tester))?->primaryModel?->provider?->driver)
        ->toBe('fake');
});

/** A design version on a room belonging to this person, with the chain the gateway walks. */
function versionOwnedBy(User $owner): DesignVersion
{
    $room = Project::factory()->ownedBy($owner)->withRoom()->create()->rooms()->firstOrFail();

    $design = Design::query()->create([
        'room_id' => $room->getKey(),
        'name' => 'Test tasarımı',
    ]);

    return DesignVersion::query()->create([
        'design_id' => $design->getKey(),
        'version_number' => 1,
    ]);
}

/** A job with no user, about a subject — which is every step of the design pipeline. */
function jobAbout(DesignVersion $version): AiJob
{
    $job = makeAiJob(AiTask::SupportAssist);

    $job->forceFill([
        'subject_type' => $version::class,
        'subject_id' => $version->getKey(),
    ])->save();

    return $job;
}

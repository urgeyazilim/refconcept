<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Enums\GenerationStage;
use App\Domains\Projects\Enums\RenderQuality;
use App\Domains\Projects\Jobs\GenerateDesignVersion;
use App\Domains\Projects\Models\DesignPlan;
use App\Domains\Projects\Models\DesignVersionEvent;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\DesignGenerationPipeline;
use App\Domains\Projects\Services\DesignVersionLauncher;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The design engine, end to end and without a provider.
 *
 * Three model calls with arithmetic in between, and every interesting case here is one
 * where something goes wrong halfway: the plan asks for furniture that does not fit, the
 * render succeeds but produces no image, the worker dies. What each of those does to the
 * customer's credits and to the version they are watching is the whole subject.
 *
 * The fake provider answers deterministically, so a failure in this suite is a failure in
 * the pipeline rather than in somebody's model that morning.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();

    // The render is fetched from the URL the provider returns, so a fake image has to be
    // fetchable. The bytes are a real PNG header; nothing decodes them here.
    Http::fake([
        '*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    Storage::fake('s3');

    $this->ledger = app(CreditLedger::class);
    $this->launcher = app(DesignVersionLauncher::class);
    $this->pipeline = app(DesignGenerationPipeline::class);

    $this->owner = User::factory()->create();
    $this->ledger->grant($this->owner, 500, CreditLotSource::Purchase, 'Test paketi');

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    // A measured room, so the placement arithmetic has something to work with.
    $this->room->forceFill([
        'width_mm' => 4_000,
        'length_mm' => 5_000,
        'height_mm' => 2_700,
    ])->save();

    $media = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'photo',
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/'.Str::uuid7().'.jpg',
        'original_name' => 'salon.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 240_000,
        'width' => 2_048,
        'height' => 1_536,
        'checksum_sha256' => hash('sha256', 'salon'),
        'position' => 0,
    ]);

    $this->room->forceFill(['primary_media_id' => $media->getKey()])->save();
    $this->room->refresh();

    $this->design = $this->room->designs()->create([
        'name' => 'Salon tasarımı',
        'created_by' => $this->owner->getKey(),
    ]);

    // Routes for the three tasks the pipeline runs.
    /*
     * A catalogue with one buyable sofa in it.
     *
     * The suite used to run against an empty shop, which was fine while the renderer was
     * handed the whole plan and drew whatever it liked. It is not fine now: a render only
     * places things a customer can buy, so "a photograph becomes a finished render" needs
     * something to be for sale. The plan these tests script asks for a kanepe up to 2200mm,
     * and this is one.
     */
    makeAiRoute(AiTask::TextEmbedding, ['credit_cost' => 0, 'max_attempts' => 1]);
    makeAiRoute(AiTask::ProductMatchRerank, ['credit_cost' => 0, 'max_attempts' => 1]);

    [$seller] = makeApprovedSeller('Tasarım Test A.Ş.', 'tasarim-test');

    $this->sofa = makeProduct($seller, makeCategory('Kanepe', 'kanepe', 'living_room'), [
        'name' => 'İskandinav meşe kanepe',
        'description' => 'Açık renk boucle kumaş, meşe ayaklı üçlü kanepe.',
        'price_minor' => 3_490_000,
        'width_mm' => 2_100,
    ]);

    app(ProductEmbedder::class)->embed($this->sofa);

    makeAiRoute(AiTask::RoomAnalysis, ['credit_cost' => 1, 'max_attempts' => 1]);
    makeAiRoute(AiTask::DesignPlan, ['credit_cost' => 1, 'max_attempts' => 1]);
    makeAiRoute(AiTask::ImageRenderDraft, ['credit_cost' => 2, 'max_attempts' => 1]);
    makeAiRoute(AiTask::ImageRenderPremium, ['credit_cost' => 6, 'max_attempts' => 1]);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('takes a room from a photograph to a finished render', function (): void {
    $version = $this->launcher->launch($this->design, null, $this->owner);

    $finished = $version->fresh();

    expect($finished?->status)->toBe(DesignVersionStatus::Ready)
        ->and($finished?->completed_at)->not->toBeNull()
        /*
         * Analysis, plan and render: three calls, one design.
         *
         * Counted by task rather than in total. Matching sits inside the pipeline too and
         * makes its own embedding and re-rank calls, and a bare total turned "the design
         * made three calls" into a number that changes whenever the shopping list does.
         */
        ->and(AiJob::query()->whereIn('task', [
            AiTask::RoomAnalysis->value,
            AiTask::DesignPlan->value,
            AiTask::ImageRenderDraft->value,
        ])->count())->toBe(3);

    // Each step left something worth keeping.
    expect(RoomAnalysis::query()->where('room_id', $this->room->getKey())->current()->exists())->toBeTrue()
        ->and(DesignPlan::query()->where('design_version_id', $version->getKey())->exists())->toBeTrue()
        ->and($finished?->assets()->where('type', 'render')->exists())->toBeTrue();

    // And the design now points at it, because somebody who just generated something
    // wants to look at it.
    expect($this->design->fresh()?->current_version_id)->toBe($version->getKey());
});

it('charges once for the whole version, not once per step', function (): void {
    $version = $this->launcher->launch($this->design, null, $this->owner);

    $wallet = $this->ledger->walletFor($this->owner);

    /*
     * Analysis 1 + plan 1 + draft render 2 = 4, held once and consumed once. Charging per
     * step would mean somebody paying for an analysis and a plan and then getting nothing
     * when the render failed, which is indefensible however defensible each charge looks.
     */
    expect($version->credit_cost)->toBe(4)
        ->and($wallet->balance)->toBe(496)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(4);

    // The three AI jobs underneath cost the customer nothing of their own.
    expect(AiJob::query()->sum('credit_cost'))->toBe(0);
});

it('reuses an analysis rather than reading the same room twice', function (): void {
    $this->launcher->launch($this->design, null, $this->owner);

    $second = $this->design->fresh();

    $version = $this->launcher->launch($second, null, $this->owner);

    /*
     * The room did not change because somebody tried a second style, so re-reading it
     * would be a second charge for an answer we already have. The quote drops the analysis
     * step too, so the customer is not billed for a call that will not happen.
     */
    expect($version->credit_cost)->toBe(3)
        ->and(RoomAnalysis::query()->count())->toBe(1)
        ->and(AiJob::query()->where('task', AiTask::RoomAnalysis->value)->count())->toBe(1);

    $skipped = DesignVersionEvent::query()
        ->where('design_version_id', $version->getKey())
        ->where('stage', GenerationStage::Analysis->value)
        ->firstOrFail();

    // Said out loud, so a customer comparing two renders can see why the second was
    // quicker and an operator can see which steps actually ran.
    expect($skipped->status)->toBe('skipped');
});

it('prices a premium render above a draft', function (): void {
    $draft = $this->launcher->quote($this->design, RenderQuality::Draft);
    $premium = $this->launcher->quote($this->design, RenderQuality::Premium);

    // 1 + 1 + 2 against 1 + 1 + 6. Both read from the routes, so an operator who moves a
    // task onto a cheaper model reprices renders without a deploy.
    expect($draft)->toBe(4)
        ->and($premium)->toBe(8);
});

it('records progress a customer can watch', function (): void {
    $version = $this->launcher->launch($this->design, null, $this->owner);

    $stages = DesignVersionEvent::query()
        ->where('design_version_id', $version->getKey())
        ->orderBy('created_at')
        ->orderBy('id')
        ->pluck('stage')
        ->all();

    /*
     * A render takes the better part of a minute and a spinner that says nothing is
     * indistinguishable from one that has hung. Every stage announces itself.
     */
    expect($stages)->toContain(GenerationStage::Queued)
        ->toContain(GenerationStage::Analysis)
        ->toContain(GenerationStage::Plan)
        ->toContain(GenerationStage::Render)
        ->toContain(GenerationStage::Save)
        ->toContain(GenerationStage::Done);
});

it('drops a placement that names no category', function (): void {
    /*
     * The shape that got past everything.
     *
     * The plan schema asked for an array and got one, full of prose, so the layout
     * validated and stored perfectly and the product search — which reads `category` and
     * nothing else — found nothing for any of it. An empty shopping list also means no
     * product photographs reach the renderer, so it was handed the room and a paragraph of
     * advice and furnished it with things nobody sells. Every stage reported success.
     *
     * Rejected rather than silently dropped, so the reason lands somewhere a person can
     * read it instead of vanishing between two green ticks.
     */
    FakeAiProvider::script(
        analysisAnswer(),
        planAnswer([
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
            ['name' => 'L Köşe Koltuk', 'position_description' => 'TV ünitesinin karşısına.'],
        ]),
        renderAnswer(),
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    $plan = DesignPlan::query()->where('design_version_id', $version->getKey())->firstOrFail();

    expect($plan->placements)->toHaveCount(1)
        ->and($plan->categories())->toBe(['kanepe'])
        ->and($plan->rejected)->toHaveCount(1)
        ->and($plan->rejected[0]['reason'])->toContain('Kategorisi');
});

it('drops a placement the room cannot take and says so', function (): void {
    // A 6000mm sideboard in a room whose longest wall is 5000mm.
    FakeAiProvider::script(
        analysisAnswer(),
        planAnswer([
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
            ['category' => 'konsol', 'wall' => 'east', 'max_width_mm' => 6_000],
        ]),
        renderAnswer(),
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    $plan = DesignPlan::query()->where('design_version_id', $version->getKey())->firstOrFail();

    /*
     * The model is good at style and bad at arithmetic. The render would look fine — an
     * image is not to scale — while the shopping list contained a sideboard that does not
     * fit through the customer's living room.
     */
    expect($plan->placements)->toHaveCount(1)
        ->and($plan->categories())->toBe(['kanepe'])
        ->and($plan->rejected)->toHaveCount(1)
        ->and($plan->rejected[0]['category'])->toBe('konsol')
        ->and($plan->rejected[0]['reason'])->toContain('sığmıyor');

    // And the customer is told, rather than left to notice the image and the list
    // disagree.
    $planEvent = DesignVersionEvent::query()
        ->where('design_version_id', $version->getKey())
        ->where('stage', GenerationStage::Plan->value)
        ->where('status', 'succeeded')
        ->firstOrFail();

    expect($planEvent->message)->toContain('1 öneri');
});

it('gives the credits back when a step fails', function (): void {
    FakeAiProvider::script(analysisAnswer());
    FakeAiProvider::scriptFailure(AiFailureKind::InvalidRequest, 'Model reddetti.');

    $version = $this->launcher->launch($this->design, null, $this->owner);

    $failed = $version->fresh();
    $wallet = $this->ledger->walletFor($this->owner);

    expect($failed?->status)->toBe(DesignVersionStatus::Failed)
        ->and($failed?->failure_reason)->toContain('Yerleşim planı hazırlanamadı')
        // Whole balance intact. A render that failed because a provider refused is our
        // problem, not the customer's.
        ->and($wallet->balance)->toBe(500)
        ->and($wallet->reserved)->toBe(0)
        ->and($wallet->lifetime_consumed)->toBe(0);
});

it('never puts the provider message in front of the customer', function (): void {
    FakeAiProvider::script(analysisAnswer());
    FakeAiProvider::scriptFailure(
        AiFailureKind::RateLimited,
        'Rate limit exceeded for gpt-image-1 in org-abc123456',
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    $reason = (string) $version->fresh()?->failure_reason;

    /*
     * The provider's own words tell a customer nothing they can act on and tell a
     * competitor which model we run. What reaches the screen is the failure kind in
     * Turkish.
     */
    expect($reason)->not->toContain('gpt-image-1')
        ->and($reason)->not->toContain('org-abc123456')
        // The failure kind, in Turkish and with its capital intact. Lowercasing it would
        // turn "İstek" into an i with a combining dot — a smudge in the middle of a
        // sentence somebody reads.
        ->and($reason)->toContain('İstek sınırı');
});

it('fails honestly when the render succeeds but produces no image', function (): void {
    FakeAiProvider::script(
        analysisAnswer(),
        planAnswer(),
        // A 200 with nothing in it: the call worked, the money was spent, and there is
        // nothing to save. Different from a provider failure and worth its own message.
        AiResult::success(imageUrls: [], inputTokens: 100),
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    expect($version->fresh()?->status)->toBe(DesignVersionStatus::Failed)
        ->and($version->fresh()?->failure_reason)->toContain('bir görsel dönmedi')
        ->and($this->ledger->walletFor($this->owner)->balance)->toBe(500);
});

it('refuses to start a render the customer cannot pay for', function (): void {
    $pauper = User::factory()->create();
    $project = Project::factory()->ownedBy($pauper)->withRoom()->create();
    $room = $project->rooms()->firstOrFail();

    $media = RoomMedia::query()->create([
        'room_id' => $room->getKey(),
        'type' => 'photo',
        'disk' => 's3',
        'storage_path' => 'room-media/'.$room->getKey().'/'.Str::uuid7().'.jpg',
        'original_name' => 'oda.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100_000,
        'width' => 1_600,
        'height' => 1_200,
        'checksum_sha256' => hash('sha256', 'oda'),
        'position' => 0,
    ]);

    $room->forceFill(['primary_media_id' => $media->getKey()])->save();

    $design = $room->designs()->create(['name' => 'Deneme', 'created_by' => $pauper->getKey()]);

    expect(fn () => $this->launcher->launch($design, null, $pauper))
        ->toThrow(InsufficientCredits::class);

    /*
     * The version is marked failed rather than deleted. Somebody who cannot afford a
     * render should see why on the design they were looking at, not find that their click
     * did nothing at all.
     */
    $version = $design->versions()->firstOrFail();

    expect($version->status)->toBe(DesignVersionStatus::Failed)
        ->and($version->failure_reason)->toContain('kredi')
        // Nothing ran for this version. Scoped to the version rather than counting every
        // job in the database, which also holds the one that embedded the test catalogue.
        ->and(AiJob::query()->where('subject_id', $version->getKey())->count())->toBe(0);
});

it('does not run a version twice when the queue delivers it twice', function (): void {
    $version = $this->launcher->launch($this->design, null, $this->owner);

    $jobsAfterFirst = AiJob::query()->count();

    // Every queue driver produces a duplicate delivery eventually. Running it again would
    // spend a second set of provider calls and overwrite an image somebody is looking at.
    (new GenerateDesignVersion((string) $version->getKey()))
        ->handle($this->pipeline);

    expect(AiJob::query()->count())->toBe($jobsAfterFirst)
        ->and($this->ledger->walletFor($this->owner)->lifetime_consumed)->toBe(4);
});

it('branches a refinement from a finished version and charges the lower price', function (): void {
    $first = $this->launcher->launch($this->design, null, $this->owner);

    $refinement = $this->launcher->launch(
        $this->design->fresh(),
        $first->fresh(),
        $this->owner,
        userPrompt: 'Kanepeyi daha koyu yap',
    );

    expect($refinement->parent_version_id)->toBe($first->getKey())
        ->and($refinement->version_number)->toBe(2)
        // The room is already read, so the refinement costs plan + render only.
        ->and($refinement->credit_cost)->toBe(3)
        ->and($refinement->fresh()?->status)->toBe(DesignVersionStatus::Ready);

    // Both survive: the point of a tree is being able to go back to the one you liked.
    expect($first->fresh()?->status)->toBe(DesignVersionStatus::Ready);
});

it('leaves an earlier good version alone when a refinement fails', function (): void {
    $first = $this->launcher->launch($this->design, null, $this->owner);

    FakeAiProvider::scriptFailure(AiFailureKind::SafetyRefusal);

    $this->launcher->launch(
        $this->design->fresh(),
        $first->fresh(),
        $this->owner,
        userPrompt: 'Uygunsuz bir şey',
    );

    // One failed refinement must not make a design that already has a good image look
    // broken.
    expect($first->fresh()?->status)->toBe(DesignVersionStatus::Ready)
        ->and($this->design->fresh()?->status->value)->toBe('ready');
});

/** A deterministic room analysis, in the shape the schema asks for. */
function analysisAnswer(): AiResult
{
    $structured = [
        'room_type' => 'living_room',
        'confidence' => 0.91,
        'style' => ['modern'],
        'dominant_colors' => ['warm_white'],
        'fixed_elements' => [
            ['type' => 'window', 'preserve' => true],
            ['type' => 'radiator', 'preserve' => true],
        ],
        'movable_objects' => [],
        'surfaces' => ['floor' => ['material' => 'wood', 'change_allowed' => false]],
        'measurement_quality' => 'estimated',
        'warnings' => [],
    ];

    return AiResult::success(
        text: json_encode($structured, JSON_UNESCAPED_UNICODE) ?: '{}',
        structured: $structured,
        inputTokens: 500,
        outputTokens: 200,
    );
}

/**
 * @param  array<int, array<string, mixed>>|null  $placements
 */
function planAnswer(?array $placements = null): AiResult
{
    $structured = [
        'style' => 'modern',
        'palette' => ['warm_white', 'oak'],
        'placements' => $placements ?? [
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
        ],
        'notes' => 'Pencere önü boş bırakıldı.',
    ];

    return AiResult::success(
        text: json_encode($structured, JSON_UNESCAPED_UNICODE) ?: '{}',
        structured: $structured,
        inputTokens: 800,
        outputTokens: 300,
    );
}

function renderAnswer(): AiResult
{
    return AiResult::success(
        imageUrls: ['https://fake.refconcept.test/renders/test.png'],
        inputTokens: 400,
        imageCount: 1,
    );
}

it('renders only the items a customer can buy', function (): void {
    /*
     * The plan is what an interior designer would ask for; the catalogue is what this shop
     * stocks, and they are not the same list. A plan calling for a sofa, a television unit
     * and a picture went to the renderer whole, against a catalogue holding only the sofa,
     * and the model drew all three — beautifully, and two of them unbuyable. The customer
     * was shown a room they could have a third of.
     */
    FakeAiProvider::script(
        analysisAnswer(),
        planAnswer([
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
            ['category' => 'tv-unitesi', 'wall' => 'north', 'max_width_mm' => 2_000],
            ['category' => 'tablo', 'wall' => 'south', 'max_width_mm' => 1_500],
        ]),
        renderAnswer(),
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    expect($version->fresh()?->status)->toBe(DesignVersionStatus::Ready);

    $render = AiJob::query()
        ->where('subject_id', $version->getKey())
        ->where('task', AiTask::ImageRenderDraft->value)
        ->firstOrFail();

    $sent = collect((array) ($render->input['plan'] ?? []))
        ->pluck('category')
        ->all();

    // The sofa, and only the sofa — with the product named, so the model is placing the
    // thing in the second photograph rather than a sofa of its own devising.
    expect($sent)->toBe(['kanepe'])
        ->and($render->input['plan'][0]['product'] ?? null)->toBe('İskandinav meşe kanepe');
});

it('refuses to render a room it cannot furnish from the catalogue', function (): void {
    /*
     * Refused rather than rendered, and this is a reversal: the empty shopping list used to
     * be treated as a shame rather than a stop, on the reasoning that a customer still got
     * their room back. What they actually got was a room furnished entirely with things
     * that do not exist, which reads as the product working — right up to the empty list
     * underneath it. Nothing is a better answer than a promise nobody can keep.
     */
    FakeAiProvider::script(
        analysisAnswer(),
        planAnswer([
            ['category' => 'perde', 'wall' => 'east', 'max_width_mm' => 4_200],
            ['category' => 'bitki', 'wall' => null, 'max_width_mm' => 500],
        ]),
    );

    $version = $this->launcher->launch($this->design, null, $this->owner);

    expect($version->fresh()?->status)->toBe(DesignVersionStatus::Failed)
        ->and($version->fresh()?->failure_reason)->toContain('katalogda bulunamadı')
        // And nothing was charged for it. A refusal the customer pays for is worse than
        // the picture it refused to draw.
        ->and($this->ledger->walletFor($this->owner)->balance)->toBe(500);
});

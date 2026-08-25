<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\FeedbackVerdict;
use App\Domains\Matching\Enums\MatchStatus;
use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Matching\Models\DesignMatchFeedback;
use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Matching\Services\ShoppingListBuilder;
use App\Domains\Projects\Models\DesignPlan;
use App\Domains\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * A plan becoming a shopping list.
 *
 * The benchmark suite next door proves the retrieval; this proves the thing built on top
 * of it — that a plan with two placements produces two groups of suggestions, that a
 * placement the catalogue cannot serve produces an empty group rather than a wrong one,
 * and that what a customer says about a suggestion actually goes somewhere.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();

    makeAiRoute(AiTask::TextEmbedding, ['credit_cost' => 0, 'max_attempts' => 1]);
    makeAiRoute(AiTask::ProductMatchRerank, ['credit_cost' => 0, 'max_attempts' => 1]);

    [$this->seller] = makeApprovedSeller('Liste Test A.Ş.', 'liste-test');

    $this->embedder = app(ProductEmbedder::class);
    $this->builder = app(ShoppingListBuilder::class);

    $sofaCategory = makeCategory('Kanepe', 'kanepe', 'living_room');
    $tableCategory = makeCategory('Sehpa', 'sehpa', 'living_room');

    $this->sofa = makeProduct($this->seller, $sofaCategory, [
        'name' => 'İskandinav meşe kanepe',
        'description' => 'Açık renk boucle kumaş, meşe ayaklı üçlü kanepe.',
        'price_minor' => 3_490_000,
        'width_mm' => 2_100,
    ]);

    $this->secondSofa = makeProduct($this->seller, $sofaCategory, [
        'name' => 'Gri üçlü kanepe',
        'description' => 'Koyu gri kumaş üçlü kanepe.',
        'price_minor' => 2_890_000,
        'width_mm' => 2_050,
    ]);

    $this->table = makeProduct($this->seller, $tableCategory, [
        'name' => 'Meşe orta sehpa',
        'description' => 'Masif meşe orta sehpa.',
        'price_minor' => 890_000,
        'width_mm' => 900,
    ]);

    foreach ([$this->sofa, $this->secondSofa, $this->table] as $product) {
        $this->embedder->embed($product);
    }

    $this->owner = User::factory()->create();
    $this->owner->forceFill(['email_verified_at' => now()])->save();

    app(CreditLedger::class)->grant($this->owner, 200, CreditLotSource::Purchase, 'Test paketi');

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();
    $this->room->forceFill(['width_mm' => 4_000, 'length_mm' => 5_000])->save();

    $this->design = $this->room->designs()->create([
        'name' => 'Salon tasarımı',
        'created_by' => $this->owner->getKey(),
    ]);

    $this->version = $this->design->versions()->create([
        'version_number' => 1,
        'created_by' => $this->owner->getKey(),
    ]);

    $this->plan = DesignPlan::query()->create([
        'design_version_id' => $this->version->getKey(),
        'style' => 'İskandinav',
        'palette' => ['açık', 'meşe'],
        'placements' => [
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
            ['category' => 'sehpa', 'wall' => null, 'max_width_mm' => 1_000],
        ],
    ]);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('builds a list grouped by the placements in the plan', function (): void {
    $matches = $this->builder->build($this->version);

    expect($matches)->not->toBeEmpty();

    $byPlacement = DesignMatch::query()
        ->forVersion((string) $this->version->getKey())
        ->get()
        ->groupBy('placement_index');

    // "For the sofa, these; for the coffee table, those" — the shape of the answer a
    // customer needs, rather than a flat list they have to sort out themselves.
    expect($byPlacement)->toHaveCount(2)
        ->and($byPlacement[0]->pluck('placement_category')->unique()->all())->toBe(['kanepe'])
        ->and($byPlacement[1]->pluck('placement_category')->unique()->all())->toBe(['sehpa']);
});

it('ranks each placement from one', function (): void {
    $this->builder->build($this->version);

    $sofas = DesignMatch::query()
        ->where('design_version_id', $this->version->getKey())
        ->where('placement_index', 0)
        ->orderBy('rank')
        ->get();

    expect($sofas->pluck('rank')->all())->toBe(range(1, $sofas->count()))
        // Descending score, so rank 1 is the best suggestion rather than merely the first
        // row the database happened to return.
        ->and($sofas->pluck('score_bps')->all())->toBe($sofas->pluck('score_bps')->sortDesc()->values()->all());
});

it('snapshots the price it showed', function (): void {
    $this->builder->build($this->version);

    $match = DesignMatch::query()->where('placement_index', 0)->orderBy('rank')->firstOrFail();

    expect($match->priceHasMoved())->toBeFalse();

    // The seller raises the price. What the customer was shown does not change, and the
    // difference is the one thing worth telling them about when they come back.
    $sku = $match->sku;
    $sku->forceFill(['list_price_minor' => $sku->list_price_minor->amountMinor + 500_000])->save();

    expect($match->fresh(['sku'])->priceHasMoved())->toBeTrue();
});

it('does not suggest the same product for two placements', function (): void {
    $bothSofas = DesignPlan::query()->where('design_version_id', $this->version->getKey())->firstOrFail();

    // A plan that asks for two sofas should get two different sofas — a list with the same
    // product twice reads as a bug whether or not it is one.
    $second = $this->design->versions()->create([
        'version_number' => 2,
        'created_by' => $this->owner->getKey(),
    ]);

    DesignPlan::query()->create([
        'design_version_id' => $second->getKey(),
        'style' => $bothSofas->style,
        'placements' => [
            ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2_200],
            ['category' => 'kanepe', 'wall' => 'north', 'max_width_mm' => 2_200],
        ],
    ]);

    $this->builder->build($second);

    $topPicks = DesignMatch::query()
        ->where('design_version_id', $second->getKey())
        ->where('rank', 1)
        ->pluck('product_id');

    expect($topPicks)->toHaveCount(2)
        ->and($topPicks->unique())->toHaveCount(2);
});

it('leaves a placement empty rather than suggesting the wrong thing', function (): void {
    $version = $this->design->versions()->create([
        'version_number' => 3,
        'created_by' => $this->owner->getKey(),
    ]);

    DesignPlan::query()->create([
        'design_version_id' => $version->getKey(),
        'placements' => [['category' => 'avize', 'max_width_mm' => 600]],
    ]);

    $this->builder->build($version);

    /*
     * The catalogue has no chandeliers. An empty group is the honest answer; falling back
     * to "the nearest thing in the whole catalogue" would put a wardrobe under a heading
     * that says chandelier, and nothing would look wrong.
     */
    expect(DesignMatch::query()->where('design_version_id', $version->getKey())->count())->toBe(0);
});

it('replaces the previous list rather than adding to it', function (): void {
    $this->builder->build($this->version);
    $first = DesignMatch::query()->where('design_version_id', $this->version->getKey())->count();

    $this->builder->build($this->version);
    $second = DesignMatch::query()->where('design_version_id', $this->version->getKey())->count();

    // Merging two generations of suggestions would produce a list whose order nobody can
    // explain.
    expect($second)->toBe($first);
});

it('keeps a suggestion within the room budget', function (): void {
    // Two placements, so each is allowed half the budget plus half again: 1,200,000.
    $this->project->forceFill(['budget_minor' => 1_600_000])->save();

    $version = $this->design->versions()->create([
        'version_number' => 4,
        'created_by' => $this->owner->getKey(),
    ]);

    DesignPlan::query()->create([
        'design_version_id' => $version->getKey(),
        'placements' => [
            ['category' => 'kanepe', 'max_width_mm' => 2_200],
            ['category' => 'sehpa', 'max_width_mm' => 1_000],
        ],
    ]);

    $this->builder->build($version->fresh());

    $matches = DesignMatch::query()->where('design_version_id', $version->getKey())->get();

    /*
     * Both sofas cost more than the share allows, so the sofa placement comes back empty
     * and the coffee table does not. Suggesting a 34,900₺ sofa to somebody who said 16,000₺
     * for the whole room is not a recommendation, it is not listening.
     */
    expect($matches->where('placement_category', 'kanepe'))->toBeEmpty()
        ->and($matches->where('placement_category', 'sehpa'))->not->toBeEmpty();
});

it('serves the list to its owner and nobody else', function (): void {
    $this->builder->build($this->version);

    $path = sprintf(
        '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches',
        $this->project->getKey(),
        $this->room->getKey(),
        $this->design->getKey(),
        $this->version->getKey(),
    );

    $response = $this->actingAs($this->owner)->getJson($path)->assertOk();

    expect($response->json('data.placements'))->toHaveCount(2)
        ->and($response->json('data.placements.0.category'))->toBe('kanepe')
        // Nothing chosen yet, so the total is zero rather than the sum of every suggestion
        // — a number five times the real one next to the word "toplam".
        ->and($response->json('data.total_minor'))->toBe(0);

    $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
});

it('records a choice and demotes the alternatives', function (): void {
    $this->builder->build($this->version);

    $sofas = DesignMatch::query()
        ->where('design_version_id', $this->version->getKey())
        ->where('placement_index', 0)
        ->orderBy('rank')
        ->get();

    $base = sprintf(
        '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches',
        $this->project->getKey(),
        $this->room->getKey(),
        $this->design->getKey(),
        $this->version->getKey(),
    );

    $this->actingAs($this->owner)
        ->postJson($base.'/'.$sofas->first()->getKey().'/choose')
        ->assertOk();

    $this->actingAs($this->owner)
        ->postJson($base.'/'.$sofas->last()->getKey().'/choose')
        ->assertOk();

    // The first choice becomes `replaced` rather than staying accepted: a list where two
    // things are chosen for one spot does not reflect what happened.
    expect($sofas->first()->fresh()->status)->toBe(MatchStatus::Replaced)
        ->and($sofas->last()->fresh()->status)->toBe(MatchStatus::Accepted);
});

it('totals only what the customer chose', function (): void {
    $this->builder->build($this->version);

    $match = DesignMatch::query()
        ->where('design_version_id', $this->version->getKey())
        ->where('placement_index', 0)
        ->orderBy('rank')
        ->firstOrFail();

    $base = sprintf(
        '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches',
        $this->project->getKey(),
        $this->room->getKey(),
        $this->design->getKey(),
        $this->version->getKey(),
    );

    $this->actingAs($this->owner)->postJson($base.'/'.$match->getKey().'/choose')->assertOk();

    $response = $this->actingAs($this->owner)->getJson($base)->assertOk();

    expect($response->json('data.total_minor'))->toBe($match->price_minor->amountMinor);
});

it('records what the customer thought and stops suggesting it', function (): void {
    $this->builder->build($this->version);

    $match = DesignMatch::query()
        ->where('design_version_id', $this->version->getKey())
        ->orderBy('rank')
        ->firstOrFail();

    $base = sprintf(
        '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches',
        $this->project->getKey(),
        $this->room->getKey(),
        $this->design->getKey(),
        $this->version->getKey(),
    );

    $this->actingAs($this->owner)
        ->postJson($base.'/'.$match->getKey().'/feedback', [
            'verdict' => FeedbackVerdict::TooExpensive->value,
            'note' => 'Bütçemin çok üstünde.',
        ])
        ->assertOk();

    $feedback = DesignMatchFeedback::query()->where('match_id', $match->getKey())->firstOrFail();

    expect($feedback->verdict)->toBe(FeedbackVerdict::TooExpensive)
        // The verdict points at a part of the pipeline, so a week of feedback is readable:
        // forty "wrong size" is a filter bug, forty "wrong style" is a modelling problem.
        ->and($feedback->reason_code)->toBe('budget')
        ->and($feedback->user_id)->toBe($this->owner->getKey())
        ->and($match->fresh()->status)->toBe(MatchStatus::Rejected);
});

it('keeps every verdict rather than the latest', function (): void {
    $this->builder->build($this->version);

    $match = DesignMatch::query()->orderBy('rank')->firstOrFail();

    $base = sprintf(
        '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches/%s/feedback',
        $this->project->getKey(),
        $this->room->getKey(),
        $this->design->getKey(),
        $this->version->getKey(),
        $match->getKey(),
    );

    foreach ([FeedbackVerdict::TooExpensive, FeedbackVerdict::WrongStyle] as $verdict) {
        $this->actingAs($this->owner)->postJson($base, ['verdict' => $verdict->value])->assertOk();
    }

    // Somebody who says "too expensive" and then "wrong style" has said two things, and
    // only one of them is about the price.
    expect(DesignMatchFeedback::query()->where('match_id', $match->getKey())->count())->toBe(2);
});

it('does not reject a suggestion the customer liked', function (): void {
    $this->builder->build($this->version);

    $match = DesignMatch::query()->orderBy('rank')->firstOrFail();

    $this->actingAs($this->owner)
        ->postJson(sprintf(
            '/api/v1/projects/%s/rooms/%s/designs/%s/versions/%s/matches/%s/feedback',
            $this->project->getKey(),
            $this->room->getKey(),
            $this->design->getKey(),
            $this->version->getKey(),
            $match->getKey(),
        ), ['verdict' => FeedbackVerdict::Good->value])
        ->assertOk();

    expect($match->fresh()->status)->toBe(MatchStatus::Suggested);
});

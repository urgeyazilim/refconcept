<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiProviderCredential;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Models\PromptTemplate;
use App\Domains\Ai\Models\PromptVersion;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

/**
 * The console an operator uses to change how AI behaves.
 *
 * Two things are being protected here and they pull in opposite directions. The console
 * has to be powerful enough that routing, prompts and the kill switch never need a
 * deploy — and every one of those levers changes what every customer gets, so each is
 * gated, audited, and refuses the configurations that would look fine and fail later.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    grantPlatformRole($this->admin, SystemRole::SuperAdmin);

    $this->outsider = User::factory()->create();
});

it('turns away anybody without the settings permission', function (): void {
    $this->actingAs($this->outsider)
        ->getJson('/api/v1/admin/ai/overview')
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->putJson('/api/v1/admin/ai/routes', ['task' => AiTask::SupportAssist->value])
        ->assertForbidden();
});

it('lists every task, including the ones nothing is routed to yet', function (): void {
    makeAiRoute(AiTask::SupportAssist);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/ai/overview')
        ->assertOk();

    $tasks = collect($response->json('data.tasks'));

    /*
     * A task with no route is the most useful row on this screen — it is a feature that
     * will fail the first time somebody uses it — so the list is built from the enum and
     * not from the routes table, which would show exactly the tasks that are already fine.
     */
    expect($tasks)->toHaveCount(count(AiTask::cases()))
        ->and($tasks->firstWhere('task', AiTask::SupportAssist->value)['route'])->not->toBeNull()
        ->and($tasks->firstWhere('task', AiTask::BudgetOptimize->value)['route'])->toBeNull();
});

it('stores an api key encrypted and never returns it', function (): void {
    $provider = AiProvider::query()->create([
        'code' => 'openai',
        'name' => 'OpenAI',
        'driver' => 'openai',
    ]);

    $secret = 'sk-test-abcdefghijklmnop1234';

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/providers/{$provider->getKey()}/credentials", [
            'label' => 'Üretim anahtarı',
            'secret' => $secret,
        ])
        ->assertCreated();

    expect($response->json('data.hint'))->toBe('1234')
        // The key is not in the response, under any key name.
        ->and(json_encode($response->json()))->not->toContain($secret);

    // Nor is it stored readable: what is on disk is ciphertext.
    $raw = (string) DB::table('ai_provider_credentials')
        ->where('provider_id', $provider->getKey())
        ->value('secret_encrypted');

    expect($raw)->not->toContain($secret)
        ->and(AiProviderCredential::query()->firstOrFail()->secret_encrypted)->toBe($secret);

    // Nor in the audit trail, which more people read than read the table.
    $entry = AuditLog::query()->where('action', 'ai.credential.rotated')->firstOrFail();

    expect(json_encode($entry->changes))->not->toContain($secret)
        ->and($entry->changes['hint'] ?? null)->toBe('1234');
});

it('leaves exactly one active key when a new one is added', function (): void {
    $provider = AiProvider::query()->create(['code' => 'g', 'name' => 'G', 'driver' => 'google']);

    foreach (['ilk-anahtar-0000000', 'ikinci-anahtar-1111'] as $secret) {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/ai/providers/{$provider->getKey()}/credentials", [
                'label' => $secret,
                'secret' => $secret,
            ])
            ->assertCreated();
    }

    /*
     * Two active keys is not a richer configuration; it is an ambiguity about which one
     * a call used, discovered while reading a provider's bill.
     */
    expect(AiProviderCredential::query()->where('provider_id', $provider->getKey())->where('is_active', true)->count())
        ->toBe(1)
        ->and($provider->fresh()?->activeCredential()?->secret_hint)->toBe('1111');
});

it('refuses a driver this build has no adapter for', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/ai/providers', [
            'code' => 'acme',
            'name' => 'Acme',
            'driver' => 'acme-super-ai',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('driver');
});

it('refuses to point an image task at a text model', function (): void {
    $provider = AiProvider::query()->create(['code' => 'fake', 'name' => 'Fake', 'driver' => 'fake']);

    $textModel = AiModel::query()->create([
        'provider_id' => $provider->getKey(),
        'code' => 'text-only',
        'name' => 'Yalnızca metin',
        'modality' => AiModality::Text,
    ]);

    /*
     * Caught on the form rather than on the first job. The provider error this produces
     * a week later reads like an outage, and the person who made the change is the one
     * least likely to be reading provider logs.
     */
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/ai/routes', [
            'task' => AiTask::ImageRenderDraft->value,
            'primary_model_id' => $textModel->getKey(),
        ])
        ->assertStatus(422);

    expect(AiTaskRoute::query()->where('task', AiTask::ImageRenderDraft->value)->exists())->toBeFalse();
});

it('saves a route once per task, however many times it is saved', function (): void {
    $provider = AiProvider::query()->create(['code' => 'fake', 'name' => 'Fake', 'driver' => 'fake']);

    $model = AiModel::query()->create([
        'provider_id' => $provider->getKey(),
        'code' => 'text-1',
        'name' => 'Metin',
        'modality' => AiModality::Text,
    ]);

    foreach ([30, 45] as $timeout) {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/admin/ai/routes', [
                'task' => AiTask::SupportAssist->value,
                'primary_model_id' => $model->getKey(),
                'timeout_seconds' => $timeout,
            ])
            ->assertOk();
    }

    $routes = AiTaskRoute::query()->where('task', AiTask::SupportAssist->value)->get();

    expect($routes)->toHaveCount(1)
        ->and($routes->first()?->timeout_seconds)->toBe(45);
});

it('pauses a task with a written reason and resumes it', function (): void {
    [$route] = makeAiRoute(AiTask::ImageRenderPremium);

    // A reason is mandatory: "somebody turned this off in March" with no explanation is
    // how a feature stays off for a month after the incident it was disabled for.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/routes/{$route->getKey()}/pause", ['reason' => 'x'])
        ->assertStatus(422);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/routes/{$route->getKey()}/pause", [
            'reason' => 'Sağlayıcıda yaygın kesinti var.',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_paused', true);

    expect(AuditLog::query()->where('action', 'ai.route.paused')->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/routes/{$route->getKey()}/resume")
        ->assertOk()
        ->assertJsonPath('data.is_paused', false)
        ->assertJsonPath('data.pause_reason', null);
});

it('closes the previous rate rather than editing it', function (): void {
    [, $model] = makeAiRoute(AiTask::SupportAssist);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/models/{$model->getKey()}/rates", [
            'input_micros_per_million_tokens' => 1_000_000,
            'output_micros_per_million_tokens' => 4_000_000,
            'effective_from' => now()->subMonth()->toIso8601String(),
        ])
        ->assertCreated();

    // A price cut, dated. Two rates saved at the same instant would leave the first one
    // closed before it began, and the endpoint refuses that rather than letting a CHECK
    // constraint explain it.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/models/{$model->getKey()}/rates", [
            'input_micros_per_million_tokens' => 750_000,
            'output_micros_per_million_tokens' => 3_000_000,
        ])
        ->assertCreated();

    // reorder(), not orderBy(): the relation already sorts newest-first, and adding a
    // second clause would leave that one winning.
    $rates = $model->costRates()->reorder('effective_from')->get();

    /*
     * Two rows, the first one closed. A job run in March has to keep reporting March's
     * price however many times the rate has moved since, or every cost report silently
     * rewrites its own history.
     */
    expect($rates)->toHaveCount(2)
        ->and($rates->first()?->effective_to)->not->toBeNull()
        ->and($rates->last()?->effective_to)->toBeNull()
        ->and($model->fresh(['costRates'])?->rateAt()?->input_micros_per_million_tokens)->toBe(750_000);
});

it('numbers prompt versions in sequence and publishes one at a time', function (): void {
    $template = PromptTemplate::query()->create([
        'code' => 'support.reply',
        'name' => 'Destek yanıtı',
        'task' => AiTask::SupportAssist,
    ]);

    makeAiRoute(AiTask::SupportAssist);

    $first = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/prompts/{$template->getKey()}/versions", [
            'user_template' => 'Soru: {{ question }}',
            'change_note' => 'İlk sürüm.',
        ])
        ->assertCreated()
        ->json('data');

    expect($first['version'])->toBe(1)
        ->and($first['placeholders'])->toBe(['question'])
        ->and($first['status'])->toBe('draft');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/prompt-versions/{$first['id']}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // Publishing points the task's route at it; a published version nothing uses is
    // half a change.
    expect(AiTaskRoute::query()->where('task', AiTask::SupportAssist->value)->value('prompt_version_id'))
        ->toBe($first['id']);

    $second = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/prompts/{$template->getKey()}/versions", [
            'user_template' => 'Müşteri sordu: {{ question }}',
            'change_note' => 'Daha nazik bir giriş.',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/prompt-versions/{$second['id']}/publish")
        ->assertOk();

    expect(PromptVersion::query()->findOrFail($first['id'])->status)->toBe('retired')
        ->and(PromptVersion::query()->findOrFail($second['id'])->version)->toBe(2);
});

it('refuses to change a published prompt, in the application and in the database', function (): void {
    $version = PromptVersion::query()->create([
        'template_id' => PromptTemplate::query()->create([
            'code' => 'plan.v1',
            'name' => 'Plan',
            'task' => AiTask::DesignPlan,
        ])->getKey(),
        'version' => 1,
        'user_template' => 'Plan üret.',
        'change_note' => 'İlk.',
    ]);

    $version->forceFill(['status' => 'published', 'published_at' => now()])->save();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/ai/prompt-versions/{$version->getKey()}", [
            'user_template' => 'Sessizce değiştirildi.',
        ])
        ->assertStatus(422);

    /*
     * And the same attempt made directly against the table is refused by a trigger. One
     * UPDATE here would silently rewrite the history of every job that ever ran against
     * this wording, so this is not a rule the application is trusted to remember.
     */
    // Wrapped in a nested transaction so the savepoint absorbs the abort: in PostgreSQL a
    // failed statement poisons the surrounding transaction, and the rest of this test
    // still has questions to ask.
    expect(fn () => DB::transaction(fn () => DB::table('prompt_versions')
        ->where('id', $version->getKey())
        ->update(['user_template' => 'Doğrudan değiştirildi.'])))
        ->toThrow(QueryException::class);

    expect(PromptVersion::query()->findOrFail($version->getKey())->user_template)->toBe('Plan üret.');
});

it('previews a prompt and names the variables the input does not supply', function (): void {
    $version = PromptVersion::query()->create([
        'template_id' => PromptTemplate::query()->create([
            'code' => 'room.v1',
            'name' => 'Oda',
            'task' => AiTask::RoomAnalysis,
        ])->getKey(),
        'version' => 1,
        'user_template' => 'Oda: {{ room_type }} / Bütçe: {{ budget_minor }}',
        'change_note' => 'İlk.',
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/ai/prompt-versions/{$version->getKey()}/preview", [
            'input' => ['room_type' => 'salon'],
        ])
        ->assertOk();

    /*
     * A placeholder with no value is left visible rather than blanked, so the omission
     * shows up here instead of producing a subtly emptier question nobody notices.
     */
    expect($response->json('data.prompt'))->toBe('Oda: salon / Bütçe: {{ budget_minor }}')
        ->and($response->json('data.missing'))->toBe(['budget_minor']);
});

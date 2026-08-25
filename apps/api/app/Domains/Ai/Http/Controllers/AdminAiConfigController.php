<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiProviderCredential;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Services\ProviderRegistry;
use App\Domains\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The AI configuration console.
 *
 * This is where an operator changes what every customer's next render costs, which
 * model produces it, and whether the feature runs at all — without a deploy. That is the
 * whole point of the routing tables, and it is also why every write here is audited and
 * gated on the same permission as system settings.
 *
 * One controller for providers, models and routes because they are one screen and one
 * decision. Splitting them would mean three policies to keep in step for a surface
 * exactly one role can reach.
 */
final class AdminAiConfigController
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Everything the console needs in one request.
     *
     * A screen that shows routes has to show which models exist to point them at, and
     * which providers those belong to; three round trips to render one table is three
     * chances to render it half-populated.
     */
    public function overview(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $providers = AiProvider::query()
            ->with(['credentials', 'models.costRates'])
            ->orderBy('name')
            ->get();

        $routes = AiTaskRoute::query()
            ->with(['primaryModel.provider', 'fallbackModel.provider', 'promptVersion.template'])
            ->get()
            ->keyBy(fn (AiTaskRoute $route): string => $route->task->value);

        return response()->json([
            'data' => [
                'providers' => $providers->map(fn (AiProvider $provider): array => $this->providerPayload($provider))->all(),

                /*
                 * Every task appears, routed or not. A task with no route is the single
                 * most useful thing this screen can show — it is a feature that will fail
                 * the first time somebody uses it — and a list built from the routes
                 * table would show exactly the tasks that are already fine.
                 */
                'tasks' => array_map(function (AiTask $task) use ($routes): array {
                    $route = $routes->get($task->value);

                    return [
                        'task' => $task->value,
                        'label' => $task->label(),
                        'modality' => $task->modality()->value,
                        'requires_structured_output' => $task->requiresStructuredOutput(),
                        'is_interactive' => $task->isInteractive(),
                        'route' => $route instanceof AiTaskRoute ? $this->routePayload($route) : null,
                    ];
                }, AiTask::cases()),

                'drivers' => $this->registry->drivers(),
                'modalities' => array_map(
                    static fn (AiModality $modality): array => [
                        'value' => $modality->value,
                        'label' => $modality->label(),
                    ],
                    AiModality::cases(),
                ),
            ],
        ]);
    }

    // --- providers -----------------------------------------------------------

    public function storeProvider(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('ai_providers', 'code')],
            'name' => ['required', 'string', 'max:120'],
            // Checked against the adapters this build actually has, so a typo is caught
            // on the form rather than on the first job that routes through it.
            'driver' => ['required', 'string', Rule::in($this->registry->drivers())],
            'base_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $provider = AiProvider::query()->create($validated);

        $this->audit->record('ai.provider.created', $provider, [
            'code' => $provider->code,
            'driver' => $provider->driver,
        ]);

        return response()->json(['data' => $this->providerPayload($provider->fresh(['credentials', 'models']))], 201);
    }

    public function updateProvider(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $provider->update($validated);

        $this->audit->record('ai.provider.updated', $provider, $validated);

        return response()->json(['data' => $this->providerPayload($provider->fresh(['credentials', 'models']))]);
    }

    /**
     * Stores an API key.
     *
     * The key is written encrypted by the model's cast and never read back by any
     * endpoint. What the console shows afterwards is the hint — the last four characters
     * — which is enough to answer "is this the key I think it is" and useless to anybody
     * who obtains it.
     *
     * Activating a new key deactivates the others in the same transaction. Two active
     * keys is not a richer configuration, it is an ambiguity about which one a call
     * used, discovered later while reading a provider's bill.
     */
    public function storeCredential(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'secret' => ['required', 'string', 'min:16', 'max:400'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $credential = DB::transaction(function () use ($provider, $validated): AiProviderCredential {
            $provider->credentials()->update(['is_active' => false]);

            return $provider->credentials()->create([
                'label' => $validated['label'],
                'secret_encrypted' => $validated['secret'],
                'secret_hint' => mb_substr($validated['secret'], -4),
                'is_active' => true,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);
        });

        // The secret is not in the audit payload, and the hint is: an audit log is read
        // by more people than the table it describes.
        $this->audit->record('ai.credential.rotated', $provider, [
            'label' => $credential->label,
            'hint' => $credential->secret_hint,
        ]);

        return response()->json([
            'data' => [
                'id' => $credential->id,
                'label' => $credential->label,
                'hint' => $credential->secret_hint,
                'is_active' => true,
            ],
        ], 201);
    }

    // --- models --------------------------------------------------------------

    public function storeModel(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'modality' => ['required', Rule::enum(AiModality::class)],
            'context_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supports_structured_output' => ['sometimes', 'boolean'],
            'supports_image_input' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model = $provider->models()->create($validated);

        $this->audit->record('ai.model.created', $model, [
            'provider' => $provider->code,
            'code' => $model->code,
        ]);

        return response()->json(['data' => $this->modelPayload($model)], 201);
    }

    public function updateModel(Request $request, AiModel $model): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'context_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supports_structured_output' => ['sometimes', 'boolean'],
            'supports_image_input' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'deprecated_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $model->forceFill($validated)->save();

        $this->audit->record('ai.model.updated', $model, $validated);

        return response()->json(['data' => $this->modelPayload($model->fresh(['provider', 'costRates']))]);
    }

    /**
     * Records what a model costs from a moment onwards.
     *
     * A new row rather than an edit to the old one, and the old one is closed at the
     * same instant the new one opens. Prices change; what a job in March cost has to keep
     * reporting March's price however many times the rate has moved since, or every cost
     * report silently rewrites its own history.
     */
    public function storeRate(Request $request, AiModel $model): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
            'input_micros_per_million_tokens' => ['required', 'integer', 'min:0'],
            'output_micros_per_million_tokens' => ['required', 'integer', 'min:0'],
            'micros_per_image' => ['sometimes', 'integer', 'min:0'],
            'micros_per_request' => ['sometimes', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'date'],
        ]);

        $from = isset($validated['effective_from']) ? now()->parse($validated['effective_from']) : now();

        $open = $model->costRates()->whereNull('effective_to')->first();

        /*
         * A rate has to start strictly after the one it replaces. The database says so
         * too — a window whose end is not after its start is refused by a CHECK — and
         * refusing it here is the difference between a sentence an operator can act on
         * and a 500 from a constraint they have never heard of.
         *
         * The case this actually catches is two corrections saved a minute apart, both
         * defaulting to now: the second would close the first at an instant before the
         * first began, leaving a price list nobody can read backwards.
         */
        if ($open !== null && $from->lte($open->effective_from)) {
            return response()->json([
                'message' => sprintf(
                    'Yeni tarife, yürürlükteki tarifenin başlangıcından (%s) sonra başlamalı.',
                    $open->effective_from->toDateTimeString(),
                ),
            ], 422);
        }

        DB::transaction(function () use ($model, $validated, $from): void {
            $model->costRates()
                ->whereNull('effective_to')
                ->update(['effective_to' => $from]);

            $model->costRates()->create([
                'currency' => $validated['currency'] ?? 'USD',
                'input_micros_per_million_tokens' => $validated['input_micros_per_million_tokens'],
                'output_micros_per_million_tokens' => $validated['output_micros_per_million_tokens'],
                'micros_per_image' => $validated['micros_per_image'] ?? 0,
                'micros_per_request' => $validated['micros_per_request'] ?? 0,
                'effective_from' => $from,
            ]);
        });

        $this->audit->record('ai.rate.recorded', $model, $validated);

        return response()->json(['data' => $this->modelPayload($model->fresh(['provider', 'costRates']))], 201);
    }

    // --- routes --------------------------------------------------------------

    public function saveRoute(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'task' => ['required', Rule::enum(AiTask::class)],
            'primary_model_id' => ['required', 'uuid', Rule::exists('ai_models', 'id')],
            'fallback_model_id' => ['sometimes', 'nullable', 'uuid', 'different:primary_model_id', Rule::exists('ai_models', 'id')],
            'prompt_version_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('prompt_versions', 'id')],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'credit_cost' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'max_cost_micros' => ['sometimes', 'integer', 'min:0'],
            'max_concurrency' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $task = AiTask::from($validated['task']);
        $primary = AiModel::query()->findOrFail($validated['primary_model_id']);

        /*
         * The check that saves the most confusion: a text model cannot render an image,
         * and pointing a task at one produces a provider error that reads like an outage
         * a week later. Refused here, where the person who made the change is looking.
         */
        if (! $primary->modality->canServe($task->modality())) {
            return response()->json([
                'message' => sprintf(
                    '"%s" görevi %s modeli ister; seçilen model %s.',
                    $task->label(),
                    $task->modality()->label(),
                    $primary->modality->label(),
                ),
            ], 422);
        }

        $route = DB::transaction(function () use ($task, $validated): AiTaskRoute {
            /** @var AiTaskRoute $route */
            $route = AiTaskRoute::query()->firstOrNew(['task' => $task->value]);

            $route->fill([...$validated, 'task' => $task->value]);
            $route->is_active = true;

            /*
             * A route whose fallback is its own primary is not a fallback; it is the same
             * call twice, and a CHECK constraint refuses it. That happens naturally when
             * an operator promotes the fallback to primary without mentioning the
             * fallback — an unambiguous intention, so the stale fallback is cleared rather
             * than the save being refused with a sentence about a column they did not
             * touch.
             */
            if ($route->fallback_model_id === $route->primary_model_id) {
                $route->fallback_model_id = null;
            }

            $route->save();

            return $route;
        });

        $this->audit->record('ai.route.saved', $route, $validated);

        return response()->json([
            'data' => $this->routePayload($route->fresh(['primaryModel.provider', 'fallbackModel.provider', 'promptVersion.template'])),
        ]);
    }

    /**
     * The kill switch.
     *
     * A pause takes a paid feature away from every customer at once, so it demands a
     * reason in writing — the reason is what the next person sees on the console, and
     * "somebody turned this off in March" with no explanation is how a feature stays off
     * for a month after the incident it was disabled for.
     */
    public function pauseRoute(Request $request, AiTaskRoute $route): JsonResponse
    {
        abort_unless($request->user()?->can('pause', $route) === true, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:400'],
        ]);

        $route->forceFill([
            'is_paused' => true,
            'pause_reason' => $validated['reason'],
        ])->save();

        $this->audit->record('ai.route.paused', $route, [
            'task' => $route->task->value,
            'reason' => $validated['reason'],
        ]);

        return response()->json(['data' => $this->routePayload($route)]);
    }

    public function resumeRoute(Request $request, AiTaskRoute $route): JsonResponse
    {
        abort_unless($request->user()?->can('pause', $route) === true, 403);

        $route->forceFill(['is_paused' => false, 'pause_reason' => null])->save();

        $this->audit->record('ai.route.resumed', $route, ['task' => $route->task->value]);

        return response()->json(['data' => $this->routePayload($route)]);
    }

    // --- payloads ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(AiProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name' => $provider->name,
            'driver' => $provider->driver,
            'base_url' => $provider->base_url,
            'is_active' => $provider->is_active,

            // Never the key. The hint answers "which key is this" and nothing else.
            'credential' => $provider->relationLoaded('credentials')
                ? (function () use ($provider): ?array {
                    $credential = $provider->activeCredential();

                    return $credential === null ? null : [
                        'id' => $credential->id,
                        'label' => $credential->label,
                        'hint' => $credential->secret_hint,
                        'expires_at' => $credential->expires_at?->toIso8601String(),
                        'has_expired' => $credential->hasExpired(),
                    ];
                })()
                : null,

            'models' => $provider->relationLoaded('models')
                ? $provider->models->map(fn (AiModel $model): array => $this->modelPayload($model))->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelPayload(AiModel $model): array
    {
        $rate = $model->relationLoaded('costRates') ? $model->rateAt() : null;

        return [
            'id' => $model->id,
            'provider_id' => $model->provider_id,
            'code' => $model->code,
            'name' => $model->name,
            'modality' => $model->modality->value,
            'modality_label' => $model->modality->label(),
            'context_tokens' => $model->context_tokens,
            'max_output_tokens' => $model->max_output_tokens,
            'supports_structured_output' => $model->supports_structured_output,
            'supports_image_input' => $model->supports_image_input,
            'is_active' => $model->is_active,
            'is_usable' => $model->isUsable(),
            'deprecated_at' => $model->deprecated_at?->toIso8601String(),
            'rate' => $rate === null ? null : [
                'currency' => $rate->currency,
                'input_micros_per_million_tokens' => $rate->input_micros_per_million_tokens,
                'output_micros_per_million_tokens' => $rate->output_micros_per_million_tokens,
                'micros_per_image' => $rate->micros_per_image,
                'micros_per_request' => $rate->micros_per_request,
                'effective_from' => $rate->effective_from->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function routePayload(AiTaskRoute $route): array
    {
        return [
            'id' => $route->id,
            'task' => $route->task->value,
            'timeout_seconds' => $route->timeout_seconds,
            'max_attempts' => $route->max_attempts,
            'credit_cost' => $route->credit_cost,
            'max_cost_micros' => $route->max_cost_micros,
            'max_concurrency' => $route->max_concurrency,
            'is_active' => $route->is_active,
            'is_paused' => $route->is_paused,
            'pause_reason' => $route->pause_reason,

            'primary_model' => $route->primaryModel === null ? null : [
                'id' => $route->primaryModel->id,
                'code' => $route->primaryModel->code,
                'name' => $route->primaryModel->name,
                'provider' => $route->primaryModel->provider?->name,
                'is_usable' => $route->primaryModel->isUsable(),
            ],

            'fallback_model' => $route->fallbackModel === null ? null : [
                'id' => $route->fallbackModel->id,
                'code' => $route->fallbackModel->code,
                'name' => $route->fallbackModel->name,
                'provider' => $route->fallbackModel->provider?->name,
                'is_usable' => $route->fallbackModel->isUsable(),
            ],

            'prompt_version' => $route->promptVersion === null ? null : [
                'id' => $route->promptVersion->id,
                'version' => $route->promptVersion->version,
                'template' => $route->promptVersion->template?->name,
                'status' => $route->promptVersion->status,
            ],
        ];
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->can('viewAny', AiTaskRoute::class) === true, 403);
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless($request->user()?->can('create', AiTaskRoute::class) === true, 403);
    }
}

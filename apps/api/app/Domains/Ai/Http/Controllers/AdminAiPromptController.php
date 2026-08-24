<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Models\PromptTemplate;
use App\Domains\Ai\Models\PromptVersion;
use App\Domains\Ai\Services\PromptRenderer;
use App\Domains\Audit\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Prompt authoring.
 *
 * The prompt is the largest single lever on output quality, which makes it the thing
 * most worth changing without a deploy and the thing most dangerous to change without a
 * record. Both are handled the same way: versions, never edits.
 *
 * A draft can be revised freely. A published version cannot be touched at all — a
 * database trigger refuses the UPDATE, so this is not a rule the application could
 * forget. Improving a published prompt means creating the next version, which leaves the
 * old one readable next to every job that ran against it.
 */
final class AdminAiPromptController
{
    public function __construct(
        private readonly PromptRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeRead($request);

        $templates = PromptTemplate::query()
            ->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (PromptTemplate $template): array => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'task' => $template->task->value,
                'task_label' => $template->task->label(),
                'description' => $template->description,
                'versions' => $template->versions->map(
                    fn (PromptVersion $version): array => $this->versionPayload($version)
                )->all(),
            ])->all(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/', Rule::unique('prompt_templates', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'task' => ['required', Rule::enum(AiTask::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $template = PromptTemplate::query()->create($validated);

        $this->audit->record('ai.prompt_template.created', $template, ['code' => $template->code]);

        return response()->json([
            'data' => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'task' => $template->task->value,
                'versions' => [],
            ],
        ], 201);
    }

    /**
     * Adds the next version of a prompt, as a draft.
     *
     * The number is assigned inside a lock on the template rather than by counting
     * existing rows. Two people saving a revision at the same moment would otherwise
     * both compute the same next number, and one of them would lose to a unique index —
     * after writing the prompt they had just spent an hour on.
     */
    public function storeVersion(Request $request, PromptTemplate $template): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'user_template' => ['required', 'string', 'max:20000'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'response_schema' => ['sometimes', 'nullable', 'array'],
            'temperature_bps' => ['sometimes', 'integer', 'min:0', 'max:20000'],
            'change_note' => ['required', 'string', 'min:4', 'max:300'],
        ]);

        $version = DB::transaction(function () use ($template, $validated, $request): PromptVersion {
            $locked = PromptTemplate::query()->lockForUpdate()->findOrFail($template->getKey());

            $next = (int) PromptVersion::query()
                ->where('template_id', $locked->getKey())
                ->max('version') + 1;

            return PromptVersion::query()->create([
                'template_id' => $locked->getKey(),
                'version' => $next,
                'user_template' => $validated['user_template'],
                'system_prompt' => $validated['system_prompt'] ?? null,
                'response_schema' => $validated['response_schema'] ?? null,
                'temperature_bps' => $validated['temperature_bps'] ?? 7000,
                'change_note' => $validated['change_note'],
                'created_by' => $request->user()?->getKey(),
            ]);
        });

        $this->audit->record('ai.prompt_version.drafted', $version, [
            'template' => $template->code,
            'version' => $version->version,
            'note' => $version->change_note,
        ]);

        return response()->json(['data' => $this->versionPayload($version)], 201);
    }

    public function updateVersion(Request $request, PromptVersion $version): JsonResponse
    {
        $this->authorizeWrite($request);

        // Said in the application as well as enforced by the trigger, because a 500 from
        // a database constraint is not an explanation anybody can act on.
        if ($version->isPublished()) {
            return response()->json([
                'message' => 'Yayımlanmış bir sürüm değiştirilemez. Yeni bir sürüm oluşturun.',
            ], 422);
        }

        $validated = $request->validate([
            'user_template' => ['sometimes', 'string', 'max:20000'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'response_schema' => ['sometimes', 'nullable', 'array'],
            'temperature_bps' => ['sometimes', 'integer', 'min:0', 'max:20000'],
            'change_note' => ['sometimes', 'string', 'min:4', 'max:300'],
        ]);

        $version->update($validated);

        return response()->json(['data' => $this->versionPayload($version->fresh() ?? $version)]);
    }

    /**
     * Publishes a version and points its task's route at it.
     *
     * Both in one transaction, because publishing without routing produces a version
     * nothing uses and routing without publishing points production at a draft somebody
     * is still editing. Neither half is useful alone.
     */
    public function publishVersion(Request $request, PromptVersion $version): JsonResponse
    {
        $this->authorizeWrite($request);

        if ($version->isPublished()) {
            return response()->json(['message' => 'Bu sürüm zaten yayımlanmış.'], 422);
        }

        DB::transaction(function () use ($version): void {
            $version->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();

            $template = $version->template;

            if ($template === null) {
                return;
            }

            // Retiring the previous version changes which one gets picked next; it does
            // not change what it said, and every job that ran against it still points at
            // the row it actually used.
            PromptVersion::query()
                ->where('template_id', $template->getKey())
                ->where('id', '!=', $version->getKey())
                ->where('status', 'published')
                ->update(['status' => 'retired']);

            AiTaskRoute::query()
                ->where('task', $template->task->value)
                ->update(['prompt_version_id' => $version->getKey()]);
        });

        $this->audit->record('ai.prompt_version.published', $version, [
            'template' => $version->template?->code,
            'version' => $version->version,
        ]);

        return response()->json(['data' => $this->versionPayload($version->fresh() ?? $version)]);
    }

    /**
     * Renders a version against sample input, without calling anything.
     *
     * The cheapest possible way to find out that a placeholder is misspelled: the answer
     * is the exact text a model would be sent, and the variables the template asked for
     * that the sample did not supply. Discovering the same typo from production output
     * means noticing that answers got slightly worse, which nobody does.
     */
    public function preview(Request $request, PromptVersion $version): JsonResponse
    {
        $this->authorizeRead($request);

        $validated = $request->validate([
            'input' => ['sometimes', 'array'],
        ]);

        /** @var array<string, mixed> $input */
        $input = $validated['input'] ?? [];

        $variables = [];

        foreach ($input as $key => $value) {
            $variables[(string) $key] = is_array($value)
                ? (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '')
                : (is_scalar($value) || $value === null ? $value : '');
        }

        return response()->json([
            'data' => [
                'system' => $version->system_prompt,
                'prompt' => $version->render($variables),
                'placeholders' => $version->placeholders(),
                'missing' => $this->renderer->missingVariables($version, $input),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(PromptVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            'is_published' => $version->isPublished(),
            'system_prompt' => $version->system_prompt,
            'user_template' => $version->user_template,
            'response_schema' => $version->response_schema,
            'temperature_bps' => $version->temperature_bps,
            'placeholders' => $version->placeholders(),
            'change_note' => $version->change_note,
            'published_at' => $version->published_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
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

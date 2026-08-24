<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Controllers;

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiFailure;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiRequest;
use App\Domains\Ai\Models\AiUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What AI has actually been doing.
 *
 * The screen somebody opens when renders are slow, when a bill is larger than expected,
 * or when a customer says the feature is broken. It answers those three questions and
 * deliberately nothing else.
 *
 * Every payload here is operational: task, model, timing, cost, failure kind. What no
 * endpoint on this controller returns is a job's `input` or `output` — those describe a
 * customer's home, and the fact that somebody is investigating a slow queue is not a
 * reason to read them. The rendered prompt is available on a single job's detail, which
 * is the narrowest place it is genuinely needed to answer "why did it say that", and it
 * carries no photograph — only a reference to one.
 */
final class AdminAiObservabilityController
{
    public function jobs(Request $request): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'task' => ['sometimes', Rule::enum(AiTask::class)],
            'status' => ['sometimes', Rule::enum(AiJobStatus::class)],
            'failure_kind' => ['sometimes', Rule::enum(AiFailureKind::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AiJob::query()
            ->with(['route.primaryModel'])
            // UUIDv7 is time-ordered, so this is "newest first" without a second index
            // and without the tie-breaking problem `created_at` has within one second.
            ->orderByDesc('id');

        foreach (['task', 'status', 'failure_kind'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $jobs = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => array_map(
                fn (AiJob $job): array => $this->jobPayload($job),
                $jobs->items(),
            ),
            'meta' => [
                'total' => $jobs->total(),
                'per_page' => $jobs->perPage(),
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
            ],
        ]);
    }

    /**
     * One job's attempts, in order.
     *
     * The useful shape when a job took four seconds and nobody knows why: three
     * timeouts against the primary and a success on the fallback is a different story
     * from one slow success, and a summary row cannot tell them apart.
     */
    public function job(Request $request, AiJob $job): JsonResponse
    {
        $this->authorize($request);

        $job->load([
            'route',
            'requests.model.provider',
            'usage',
            'failures',
        ]);

        return response()->json([
            'data' => [
                ...$this->jobPayload($job),

                'attempts' => $job->requests->map(function (AiRequest $attempt) use ($job): array {
                    $usage = $job->usage->firstWhere('request_id', $attempt->getKey());
                    $failure = $job->failures->firstWhere('request_id', $attempt->getKey());

                    return [
                        'attempt' => $attempt->attempt,
                        'is_fallback' => $attempt->is_fallback,
                        'model' => $attempt->model?->code,
                        'provider' => $attempt->model?->provider?->name,
                        'status' => $attempt->status,
                        'http_status' => $attempt->http_status,
                        'latency_ms' => $attempt->latency_ms,
                        'input_tokens' => $usage->input_tokens ?? 0,
                        'output_tokens' => $usage->output_tokens ?? 0,
                        'cost_micros' => $usage->cost_micros ?? 0,
                        'failure' => $failure === null ? null : [
                            'kind' => $failure->kind->value,
                            'label' => $failure->kind->label(),
                            'message' => $failure->message,
                            'was_retryable' => $failure->was_retryable,
                        ],
                        // The text that was sent, so "why did it answer that" is
                        // answerable. Never the photograph it referred to.
                        'rendered_prompt' => $attempt->rendered_prompt,
                    ];
                })->all(),

                /*
                 * Refusals decided before any provider was contacted have no request to
                 * hang off — a paused route, a cost ceiling — and they are exactly the
                 * failures somebody is looking for on this screen.
                 */
                'pre_flight_failures' => $job->failures
                    ->filter(static fn (AiFailure $failure): bool => $failure->request_id === null)
                    ->map(static fn (AiFailure $failure): array => [
                        'kind' => $failure->kind->value,
                        'label' => $failure->kind->label(),
                        'message' => $failure->message,
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * Spend and reliability over a window, grouped by task.
     *
     * Aggregated in the database rather than in PHP. A month of usage rows is not a
     * collection to load into memory to sum a column, and the day this screen matters
     * most is the day the table is largest.
     */
    public function usage(Request $request): JsonResponse
    {
        $this->authorize($request);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $since = now()->subDays($validated['days'] ?? 7);

        /*
         * Dropped to the query builder deliberately: these rows are aggregates, not
         * usage records. Hydrating them as models would produce objects whose casts
         * and relations describe a row that does not exist.
         */
        $byTask = AiUsage::query()
            ->since($since)
            ->toBase()
            ->select('task')
            ->selectRaw('count(*) as attempts')
            ->selectRaw('sum(input_tokens) as input_tokens')
            ->selectRaw('sum(output_tokens) as output_tokens')
            ->selectRaw('sum(image_count) as images')
            ->selectRaw('sum(cost_micros) as cost_micros')
            ->selectRaw('avg(latency_ms) as avg_latency_ms')
            ->groupBy('task')
            ->get();

        $jobOutcomes = AiJob::query()
            ->where('created_at', '>=', $since)
            ->toBase()
            ->select('task', 'status')
            ->selectRaw('count(*) as total')
            ->groupBy('task', 'status')
            ->get();

        $failures = AiFailure::query()
            ->where('created_at', '>=', $since)
            ->toBase()
            ->select('kind')
            ->selectRaw('count(*) as total')
            ->groupBy('kind')
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        return response()->json([
            'data' => [
                'since' => $since->toIso8601String(),

                'tasks' => $byTask->map(function ($row) use ($jobOutcomes): array {
                    $task = AiTask::from((string) $row->task);
                    $outcomes = $jobOutcomes->where('task', $task->value);

                    $succeeded = (int) ($outcomes->firstWhere('status', AiJobStatus::Succeeded->value)->total ?? 0);
                    $failed = (int) ($outcomes->firstWhere('status', AiJobStatus::Failed->value)->total ?? 0);

                    return [
                        'task' => $task->value,
                        'label' => $task->label(),
                        'attempts' => (int) $row->attempts,
                        'input_tokens' => (int) $row->input_tokens,
                        'output_tokens' => (int) $row->output_tokens,
                        'images' => (int) $row->images,
                        'cost_micros' => (int) $row->cost_micros,
                        'avg_latency_ms' => (int) round((float) $row->avg_latency_ms),
                        'jobs_succeeded' => $succeeded,
                        'jobs_failed' => $failed,

                        /*
                         * Expressed in basis points rather than as a float, for the same
                         * reason every other rate in this system is: a success rate ends
                         * up in a report next to a cost, and one of the two being a float
                         * is how the other becomes one.
                         */
                        'success_bps' => ($succeeded + $failed) === 0
                            ? null
                            : intdiv($succeeded * 10_000, $succeeded + $failed),
                    ];
                })->all(),

                'failures' => $failures->map(static function ($row): array {
                    $kind = AiFailureKind::from((string) $row->kind);

                    return [
                        'kind' => $kind->value,
                        'label' => $kind->label(),
                        'total' => (int) $row->total,
                        'is_retryable' => $kind->isRetryable(),
                    ];
                })->all(),

                'total_cost_micros' => (int) $byTask->sum('cost_micros'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(AiJob $job): array
    {
        return [
            'id' => $job->id,
            'task' => $job->task->value,
            'task_label' => $job->task->label(),
            'status' => $job->status->value,
            'status_label' => $job->status->label(),
            'attempts' => $job->attempts,
            'credit_cost' => $job->credit_cost,
            'cost_micros' => $job->total_cost_micros,
            'latency_ms' => $job->total_latency_ms,
            'duration_ms' => $job->wallClockMs(),
            'failure_kind' => $job->failure_kind?->value,
            'failure_label' => $job->failure_kind?->label(),
            'failure_reason' => $job->failure_reason,
            'subject_type' => $job->subject_type === null ? null : class_basename($job->subject_type),
            'created_at' => $job->created_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->can('viewOperations', AiJob::class) === true, 403);
    }
}

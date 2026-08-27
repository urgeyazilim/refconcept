<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiFailure;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiRequest;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Models\AiUsage;
use App\Domains\Ai\Models\PromptVersion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place RefConcept talks to a model.
 *
 * Everything that is *policy* lives here rather than in an adapter: which model to
 * try, how many times, when to give up on one provider and try another, what a call is
 * allowed to cost, and what to record about all of it. An adapter that also retried
 * would be a second place the retry rule lives, and the second place is always the one
 * that drifts.
 *
 * The order of operations matters and is worth stating:
 *
 *  1. **Refuse early.** No route, a paused route, or an estimate over the cost ceiling
 *     — all decided before any provider is contacted, because a call that should not
 *     happen costs money the moment it does.
 *  2. **Try the primary, then the fallback.** Retries stay on the same model while the
 *     failure looks transient; a failure that will repeat moves on immediately rather
 *     than spending three timeouts proving it.
 *  3. **Record every attempt**, successful or not, with what it consumed. A provider
 *     that read the input and then refused still charges for the input.
 *  4. **Validate structured output** before calling it a success. A room analysis that
 *     came back as prose is a failure now, not a mystery two steps downstream.
 */
final class AiGateway
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly PromptRenderer $prompts,
        private readonly StructuredOutputValidator $validator,
        private readonly ProviderCostInLira $cost,
        private readonly InlineImageLoader $images,
    ) {}

    /**
     * Runs a job to completion, or to a failure it can explain.
     *
     * Never throws for a provider problem: the job carries the outcome, and a caller
     * that wanted an exception can ask the job whether it succeeded. Callers are queue
     * workers, and a queue worker that has to catch six exception types to decide
     * whether to retry is a queue worker that will get one of them wrong.
     */
    public function run(AiJob $job): AiJob
    {
        $route = $this->resolveRoute($job);

        if ($route === null) {
            return $this->fail(
                $job,
                AiFailureKind::NoRouteConfigured,
                sprintf('"%s" görevi için etkin bir yönlendirme tanımlı değil.', $job->task->value),
                attempt: 0,
            );
        }

        if ($route->is_paused) {
            return $this->fail(
                $job,
                AiFailureKind::KillSwitchEngaged,
                $route->pause_reason ?? 'Bu görev geçici olarak durduruldu.',
                attempt: 0,
            );
        }

        $models = $route->candidateModels();

        if ($models === []) {
            return $this->fail(
                $job,
                AiFailureKind::NoRouteConfigured,
                'Yönlendirmedeki modellerin hiçbiri kullanılabilir durumda değil.',
                attempt: 0,
            );
        }

        $job->forceFill([
            'status' => AiJobStatus::Running,
            'route_id' => $route->getKey(),
            /*
             * The cost is deliberately left alone. The dispatcher decided it when the job
             * was accepted, and it may legitimately differ from the route: the design
             * pipeline runs its three model calls at zero because the version above them
             * holds the whole price. Re-reading it from the route here would silently
             * charge for those steps a second time.
             */
            'started_at' => now(),
        ])->save();

        $promptVersion = $route->promptVersion;
        $attempt = 0;
        $lastResult = null;

        foreach ($models as $index => $model) {
            $isFallback = $index > 0;

            for ($try = 0; $try < $route->max_attempts; $try++) {
                $attempt++;

                $call = $this->buildCall($job, $route, $model, $promptVersion);

                // Estimated before the call, because a ceiling checked afterwards is a
                // ceiling that has already been exceeded.
                $estimate = $this->estimateCost($call, $model);

                if ($estimate > $route->max_cost_micros) {
                    $lastResult = AiResult::failure(
                        AiFailureKind::CostCapExceeded,
                        sprintf(
                            'Tahmini maliyet (%d micro) bu görev için tanımlı üst sınırı (%d micro) aşıyor.',
                            $estimate,
                            $route->max_cost_micros,
                        ),
                    );

                    $this->recordAttempt($job, $route, $model, $call, $lastResult, $attempt, $isFallback, 0);

                    // A ceiling problem follows us to every model and every retry.
                    return $this->finish($job, $lastResult, $attempt);
                }

                $startedAt = microtime(true);

                try {
                    $result = $this->registry->for($model->provider)->execute($call);
                } catch (Throwable $e) {
                    /*
                     * An adapter is not supposed to throw, and one that does is a bug in
                     * the adapter rather than a provider outage. Caught anyway: a job
                     * that dies with an unhandled exception leaves a customer watching a
                     * spinner forever, which is worse than a wrong classification.
                     */
                    $result = AiResult::failure(
                        AiFailureKind::ProviderError,
                        'Sağlayıcı bağdaştırıcısı beklenmeyen bir hata verdi: '.$e->getMessage(),
                    );
                }

                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

                // Structured output is validated here, not in the adapter: what a valid
                // answer looks like is the application's business, and an adapter that
                // knew would have to know every task.
                if ($result->successful && $call->expectsStructuredOutput()) {
                    $result = $this->validator->validate($result, $call->responseSchema ?? []);
                }

                $this->recordAttempt($job, $route, $model, $call, $result, $attempt, $isFallback, $latencyMs);

                if ($result->successful) {
                    return $this->finish($job, $result, $attempt);
                }

                $lastResult = $result;

                if (! $result->isRetryable()) {
                    // No point trying the same model again; break to the fallback, or
                    // out entirely if this failure would follow us there too.
                    break;
                }
            }

            if ($lastResult !== null && ! $lastResult->warrantsFallback()) {
                break;
            }
        }

        return $this->finish(
            $job,
            $lastResult ?? AiResult::failure(AiFailureKind::ProviderError, 'Sağlayıcıdan yanıt alınamadı.'),
            $attempt,
        );
    }

    /**
     * How many jobs this user already has in flight.
     *
     * Concurrency is per user rather than global because the thing being protected is
     * fairness: one person queueing forty renders should not put everybody else behind
     * them.
     */
    public function inFlightFor(string $userId, AiTask $task): int
    {
        return AiJob::query()
            ->where('user_id', $userId)
            ->where('task', $task->value)
            ->inFlight()
            ->count();
    }

    public function resolveRoute(AiJob $job): ?AiTaskRoute
    {
        return AiTaskRoute::query()
            ->with(['primaryModel.provider.credentials', 'fallbackModel.provider.credentials', 'promptVersion'])
            ->where('task', $job->task->value)
            ->active()
            ->first();
    }

    // --- internals -----------------------------------------------------------

    private function buildCall(
        AiJob $job,
        AiTaskRoute $route,
        AiModel $model,
        ?PromptVersion $promptVersion,
    ): AiCall {
        $rendered = $this->prompts->render($job, $promptVersion);

        /** @var array<int, string> $imageUrls */
        $imageUrls = (array) ($job->input['image_urls'] ?? []);

        return new AiCall(
            task: $job->task,
            model: $model,
            prompt: $rendered['prompt'],
            systemPrompt: $rendered['system'],
            imageUrls: $imageUrls,
            /*
             * Read here, inside our own network, and sent to the provider as bytes.
             *
             * Handing over a signed URL was both broken and unsafe: Gemini does not fetch
             * arbitrary URLs, and a link to somebody's room photograph must never leave
             * this system at all.
             */
            imageBlobs: $this->images->load($imageUrls),
            // Only ask for a schema when the task needs one *and* a prompt version
            // defines one; demanding JSON with no shape to check it against would turn
            // every free-text answer into a failure.
            responseSchema: $job->task->requiresStructuredOutput()
                ? ($promptVersion->response_schema ?? [])
                : null,
            temperatureBps: $promptVersion->temperature_bps ?? 7000,
            timeoutSeconds: $route->timeout_seconds,
            apiKey: $model->provider?->activeCredential()?->secret_encrypted,
        );
    }

    /**
     * What this call would cost if the answer is as long as the model allows.
     *
     * Pessimistic on purpose. An optimistic estimate that passes the ceiling and then
     * exceeds it has protected nothing, and the whole reason for a ceiling is the run
     * nobody predicted.
     */
    private function estimateCost(AiCall $call, AiModel $model): int
    {
        $rate = $model->rateAt();

        if ($rate === null) {
            // A model with no rate on file costs nothing as far as we can prove. Said
            // out loud rather than assumed: the alternative is refusing every call
            // until somebody fills in a price list.
            return 0;
        }

        $inputTokens = (int) ceil(mb_strlen($call->prompt.($call->systemPrompt ?? '')) / 4);
        $outputTokens = $model->max_output_tokens ?? 2_000;
        $images = $call->modality() === AiModality::Image ? 1 : 0;

        return $rate->costFor($inputTokens, $outputTokens, $images);
    }

    /** Writes the request, its usage and — when it failed — the failure. */
    private function recordAttempt(
        AiJob $job,
        AiTaskRoute $route,
        AiModel $model,
        AiCall $call,
        AiResult $result,
        int $attempt,
        bool $isFallback,
        int $latencyMs,
    ): void {
        DB::transaction(function () use ($job, $route, $model, $call, $result, $attempt, $isFallback, $latencyMs): void {
            $request = AiRequest::query()->create([
                'job_id' => $job->getKey(),
                'attempt' => $attempt,
                'provider_id' => $model->provider_id,
                'model_id' => $model->getKey(),
                'prompt_version_id' => $route->prompt_version_id,
                'is_fallback' => $isFallback,
                'rendered_prompt' => $call->prompt,
                'status' => $result->successful ? 'succeeded' : 'failed',
                'http_status' => $result->httpStatus,
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);

            $rate = $model->rateAt();

            /*
           * The provider's own figure, in the provider's own currency.
           *
           * Google quotes dollars per million tokens, so this can be USD. It is converted
           * once, on the next line, and what gets stored is lira — relabelling dollars as
           * lira would show an operator a number wrong by the whole exchange rate, and
           * wrong silently.
           */
            $quoted = $rate?->costFor(
                $result->inputTokens,
                $result->outputTokens,
                $result->imageCount,
            ) ?? 0;

            $cost = $this->cost->convert($quoted, $rate?->currency);

            AiUsage::query()->create([
                'request_id' => $request->getKey(),
                'job_id' => $job->getKey(),
                'model_id' => $model->getKey(),
                'task' => $job->task,
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'image_count' => $result->imageCount,
                'cost_micros' => $cost,
                // Always the platform's own currency: the conversion happened above.
                'currency' => $this->cost->currency(),
                // Credits are charged once for the job, not once per attempt: a
                // customer must not pay three times because a provider was flaky.
                'credits_charged' => 0,
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);

            if (! $result->successful && $result->failureKind !== null) {
                AiFailure::query()->create([
                    'job_id' => $job->getKey(),
                    'request_id' => $request->getKey(),
                    'kind' => $result->failureKind,
                    'message' => $result->failureMessage ?? '',
                    // The decision as it was made, not as a later release would make it.
                    'was_retryable' => $result->failureKind->isRetryable(),
                    'attempt' => $attempt,
                    'created_at' => now(),
                ]);
            }

            $job->forceFill([
                'attempts' => $attempt,
                'total_cost_micros' => $job->total_cost_micros + $cost,
                'total_latency_ms' => $job->total_latency_ms + $latencyMs,
            ])->save();
        });
    }

    private function finish(AiJob $job, AiResult $result, int $attempt): AiJob
    {
        if ($result->successful) {
            $job->forceFill([
                'status' => AiJobStatus::Succeeded,
                'output' => [
                    'text' => $result->text,
                    'structured' => $result->structured,
                    'image_urls' => $result->imageUrls,
                    // References, not bytes: the images live on the private disk and the
                    // job carries the path. A megabyte of base64 in this column would be
                    // a table nobody can read and a query nobody can run.
                    'image_refs' => $result->imageRefs,
                    /*
                     * A vector is a few kilobytes of numbers, which is small enough to
                     * carry here and saves the caller a second round trip. It is written
                     * to its real home — a pgvector column — by whoever asked for it.
                     */
                    'embedding' => $result->embedding,
                ],
                'attempts' => $attempt,
                'finished_at' => now(),
            ])->save();

            return $job;
        }

        return $this->fail(
            $job,
            $result->failureKind ?? AiFailureKind::ProviderError,
            $result->failureMessage ?? 'Bilinmeyen hata.',
            $attempt,
        );
    }

    private function fail(AiJob $job, AiFailureKind $kind, string $message, int $attempt): AiJob
    {
        DB::transaction(function () use ($job, $kind, $message, $attempt): void {
            $job->forceFill([
                'status' => AiJobStatus::Failed,
                'failure_kind' => $kind,
                'failure_reason' => $message,
                'attempts' => $attempt,
                'finished_at' => now(),
            ])->save();

            /*
             * Refusals decided before any provider was contacted still get a failure
             * row. Otherwise "no route configured" is invisible in the dashboard that
             * exists to show why jobs are failing, which is exactly when somebody is
             * looking at it.
             */
            if ($attempt === 0) {
                AiFailure::query()->create([
                    'job_id' => $job->getKey(),
                    'kind' => $kind,
                    'message' => $message,
                    'was_retryable' => false,
                    'attempt' => 0,
                    'created_at' => now(),
                ]);
            }
        });

        return $job;
    }
}

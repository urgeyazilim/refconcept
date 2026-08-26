<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Administration\Services\Features;
use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Jobs\RunAiJob;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The front door to the AI gateway.
 *
 * Every feature that wants a model — a render, a room analysis, a support answer —
 * comes through here rather than constructing an {@see AiJob} and dispatching it
 * itself. Three things have to happen on the way in, and each of them is the kind of
 * check that gets forgotten in one call site out of six:
 *
 *  - **Idempotency.** A customer who taps "render" twice, or a mobile client that
 *    retries a request whose response it never saw, must not be charged twice. The key
 *    is the caller's to choose, and a repeat returns the existing job rather than an
 *    error — the second tap should look like the first one worked, because it did.
 *  - **Concurrency.** One person queueing forty renders should not put everybody else
 *    behind them. The limit is per user per task and comes from the route.
 *  - **The kill switch, early.** A paused task refuses here, before the row is written,
 *    so a queue full of jobs that will all fail identically never accumulates.
 *
 * Deliberately *not* here: charging credits. That is Phase 7's, and putting a
 * half-finished version of it in this class now would mean two places that debit a
 * balance by the time the real one exists.
 */
final class AiJobDispatcher
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiJobCredits $credits,
        private readonly Features $features,
    ) {}

    /**
     * Accepts one job, or hands back the one this key already made.
     *
     * Everything a job needs before anything runs it: the route resolved, the kill switch
     * checked, the concurrency limit applied and the credits held. What it deliberately
     * does not do is decide *where* it runs — {@see dispatch()} sends it to a worker and
     * {@see runInline()} runs it here.
     *
     * @param  array<string, mixed>  $input
     * @param  int|null  $creditCostOverride  when the cost is borne somewhere else
     *
     * @throws AiJobRefused when the task is unavailable or the caller is at their limit
     */
    public function accept(
        AiTask $task,
        array $input,
        ?User $user = null,
        ?Model $subject = null,
        ?string $idempotencyKey = null,
        ?int $creditCostOverride = null,
    ): AiJob {
        if ($idempotencyKey !== null) {
            $existing = AiJob::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        /*
         * The platform-wide switch, before the per-route one.
         *
         * The route's pause reason answers "this task is broken"; the flag answers "stop
         * spending money at the provider right now", which is a decision somebody takes
         * during an incident and needs to be one click rather than a deploy. Jobs already
         * running are left alone: killing work a customer has paid credits for would turn
         * a provider problem into a refund queue.
         */
        if ($this->features->disabled('ai.design-generation', $user?->getKey())) {
            throw AiJobRefused::unavailable(
                $task,
                'Yapay zekâ özellikleri şu anda geçici olarak kapalı.',
            );
        }

        $job = new AiJob([
            'task' => $task,
            'input' => $input,
            'user_id' => $user?->getKey(),
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);

        $route = $this->gateway->resolveRoute($job);

        if ($route === null || ! $route->isUsable()) {
            /*
             * Refused as an exception rather than as a failed job, because nothing has
             * been attempted and there is nothing to inspect afterwards. The caller is
             * an HTTP request that should tell the customer the feature is off right
             * now, not hand them a job id to poll for a failure that is already known.
             */
            throw AiJobRefused::unavailable(
                $task,
                $route->pause_reason ?? 'Bu özellik şu anda kullanılamıyor.',
            );
        }

        if ($user !== null) {
            $inFlight = $this->gateway->inFlightFor((string) $user->getKey(), $task);

            if ($inFlight >= $route->max_concurrency) {
                throw AiJobRefused::tooManyInFlight($task, $inFlight, $route->max_concurrency);
            }
        }

        $job->forceFill([
            'route_id' => $route->getKey(),
            /*
             * The route says what this task costs; a caller may say it is billed
             * elsewhere. The design pipeline uses that: a customer pays for a design
             * version, not for the three model calls inside one, so the steps run at
             * zero and the version holds the total.
             */
            'credit_cost' => $creditCostOverride ?? $route->credit_cost,
            'status' => AiJobStatus::Queued,
        ])->save();

        /*
         * The credits are held now, not when the worker starts.
         *
         * A customer with three credits should be told so while they are still looking at
         * the button, not handed a job id and a failure four seconds later. The hold also
         * stops somebody queueing ten renders they can afford one of.
         *
         * Held after the row exists because the reservation points at it. If the hold
         * fails the InsufficientCredits exception propagates and the job stays queued with
         * nothing to run it — so the row is removed on the way out rather than left as
         * litter a customer would see on their history.
         */
        try {
            $this->credits->hold($job, $user);
        } catch (InsufficientCredits $e) {
            $job->delete();

            throw $e;
        }

        return $job;
    }

    /**
     * Accepts a job and hands it to a worker.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AiJobRefused
     */
    public function dispatch(
        AiTask $task,
        array $input,
        ?User $user = null,
        ?Model $subject = null,
        ?string $idempotencyKey = null,
        ?int $creditCostOverride = null,
    ): AiJob {
        $job = $this->accept($task, $input, $user, $subject, $idempotencyKey, $creditCostOverride);

        if ($job->status->isTerminal()) {
            // An idempotency key found a job that has already run. Queueing it again
            // would re-run finished work and charge for it a second time.
            return $job;
        }

        /*
         * Dispatched after the transaction commits, not inside it. A worker is a
         * separate process and can pick the job up within milliseconds — before an
         * uncommitted row is visible to it — and the symptom is a job that fails to
         * find itself, intermittently, under load.
         */
        DB::afterCommit(static function () use ($job): void {
            RunAiJob::dispatch($job->getKey());
        });

        return $job;
    }

    /**
     * Accepts a job and runs it here and now, never touching the queue.
     *
     * For a caller that is already on a worker and needs the answer before it can take
     * its next step — the design pipeline, which has to read the room before it can plan
     * the layout. Queueing a nested job and then waiting for it would be a worker waiting
     * on a worker, and under a synchronous queue driver it would run the job twice.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AiJobRefused
     */
    public function runInline(
        AiTask $task,
        array $input,
        ?User $user = null,
        ?Model $subject = null,
        ?string $idempotencyKey = null,
        ?int $creditCostOverride = null,
    ): AiJob {
        $job = $this->accept($task, $input, $user, $subject, $idempotencyKey, $creditCostOverride);

        if ($job->status->isTerminal()) {
            return $job;
        }

        return $this->runNow($job);
    }

    /**
     * Runs a job in this process instead of on a worker.
     *
     * For tasks a customer is actually waiting on — a query rewrite that shapes a
     * search results page is worthless three seconds after the page rendered. Still
     * goes through the same gateway, so routing, cost caps and recording behave
     * identically; only the transport differs.
     */
    public function runNow(AiJob $job): AiJob
    {
        $ran = $this->gateway->run($job);

        // Settled here as well as on the worker, because this path never reaches one.
        $this->credits->settle($ran);

        return $ran;
    }

    /**
     * Marks a job cancelled, if it has not already finished.
     *
     * Returns whether it changed anything, so a caller can tell the difference between
     * "stopped it" and "it had already finished" — which are different sentences to
     * show somebody.
     */
    public function cancel(AiJob $job, string $reason = 'Kullanıcı iptal etti.'): bool
    {
        if ($job->status->isTerminal()) {
            return false;
        }

        $job->forceFill([
            'status' => AiJobStatus::Cancelled,
            'failure_kind' => null,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ])->save();

        // Nothing ran, so nothing is owed.
        $this->credits->releaseFor($job);

        return true;
    }

    /**
     * Records that a job died in a way the gateway never saw.
     *
     * A worker killed mid-run, a fatal error, a deploy that restarted the queue: the
     * gateway's failure handling never got the chance to write anything, so the job
     * would sit at `running` forever and a customer would watch a spinner that will
     * never stop. Called from the queue job's `failed()` hook.
     */
    public function markCrashed(AiJob $job, Throwable $e): void
    {
        if ($job->status->isTerminal()) {
            return;
        }

        $job->forceFill([
            'status' => AiJobStatus::Failed,
            'failure_kind' => AiFailureKind::ProviderError,
            'failure_reason' => 'İş beklenmedik şekilde sonlandı: '.$e->getMessage(),
            'finished_at' => now(),
        ])->save();

        // A worker that died still leaves a customer owed their credits back.
        $this->credits->settle($job);
    }
}

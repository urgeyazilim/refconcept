<?php

declare(strict_types=1);

namespace App\Domains\Ai\Jobs;

use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Services\AiGateway;
use App\Domains\Ai\Services\AiJobDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one AI job on a worker.
 *
 * A thin shell on purpose. Every decision that matters — which model, how many
 * attempts, when to fall back, what it may cost — is the gateway's, and the queue's
 * only contributions are "not in the web request" and "survives a restart".
 *
 * `$tries = 1` deserves an explanation, because one is an unusual number for a queued
 * job. The gateway already retries, with a policy that knows the difference between a
 * timeout and a refusal; letting the queue retry on top would multiply the two —
 * three attempts inside, three outside, nine calls to a provider that is rate-limiting
 * us, all charged. Anything the gateway decided not to retry, the queue must not retry
 * either.
 *
 * The id is passed rather than the model. A serialised model is a snapshot of a row as
 * it was when the job was queued, and this one is written to by the gateway the moment
 * it starts; re-reading is the difference between running the current job and running
 * a copy of an old one.
 */
final class RunAiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Long enough for the slowest route, with room for the gateway's own retries.
     *
     * If the worker were to give up first, the gateway would never write the failure
     * and the job would be left at `running` forever — the exact state
     * {@see AiJobDispatcher::markCrashed()} exists to clean up, and one worth not
     * reaching by construction.
     */
    public int $timeout = 600;

    public function __construct(public readonly string $jobId) {}

    public function handle(AiGateway $gateway): void
    {
        $job = AiJob::query()->find($this->jobId);

        if ($job === null) {
            // Deleted between queueing and running. Nothing to do, and nothing worth
            // failing over: the customer who deleted it is not waiting for an answer.
            return;
        }

        if ($job->status->isTerminal()) {
            // Already cancelled, or already run — a duplicate delivery, which every
            // queue driver will produce eventually. Running it again would charge twice.
            return;
        }

        $gateway->run($job);
    }

    /**
     * Called when the worker itself died: a fatal error, a timeout, a deploy.
     *
     * The gateway never got to write anything, so the job would sit at `running`
     * forever and a customer would watch a spinner with no end. Recorded as a failure
     * with what little is known, which is better than silence.
     */
    public function failed(Throwable $e): void
    {
        $job = AiJob::query()->find($this->jobId);

        if ($job === null) {
            return;
        }

        app(AiJobDispatcher::class)->markCrashed($job, $e);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['ai', 'ai-job:'.$this->jobId];
    }
}

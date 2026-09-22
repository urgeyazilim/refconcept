<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Ai\Jobs\RunAiJob;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Services\DesignGenerationPipeline;
use App\Domains\Projects\Services\DesignVersionTree;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one design version on a worker.
 *
 * A thin shell, like {@see RunAiJob}. Everything that matters is the
 * pipeline's; the queue's contributions are "not in the web request" and "survives a
 * restart".
 *
 * `$tries = 1` for the same reason as the AI job, one level up: the gateway already
 * retries each model call with a policy that knows a timeout from a refusal. A queue retry
 * on top would re-run the *whole* pipeline — a second analysis, a second plan, a second
 * render — for a failure the gateway already decided was not worth another attempt.
 *
 * The id is passed rather than the model. A serialised version is a snapshot of a row that
 * the pipeline writes to the moment it starts.
 */
final class GenerateDesignVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Longer than everything inside it, with room to spare.
     *
     * Not three model calls. A design is the plan, one ranking call for every placement it
     * produced, the render and the check — and the plans have thirteen placements in them.
     * Fifteen minutes was written when a step took twenty seconds; on the engine that reads
     * and decides now, the plan alone takes eighty and a ranking call is of that order, so
     * thirteen placements put the sum past the limit and the worker would kill the run at
     * the last step. The money is spent by then. Every model call has already been made and
     * paid for, and the customer gets a version stuck at `generating` for the two minutes
     * until {@see failed()} tidies it away.
     *
     * Half an hour, therefore, until the ranking stops being one call per placement — that
     * is the real answer and it is a change to the pipeline, not to a number here.
     *
     * Generous on purpose in the other direction too: if the worker gives up first the
     * pipeline never writes the failure, and the version sits at `generating` with a
     * spinner that has no end. That is the state {@see failed()} exists to clean up, and
     * one worth not reaching by construction.
     */
    public int $timeout = 1_800;

    /** The slow queue: see RunAiJob. A design render is minutes, not milliseconds. */
    public function __construct(public readonly string $versionId)
    {
        $this->onQueue('ai');
    }

    public function handle(DesignGenerationPipeline $pipeline): void
    {
        $version = DesignVersion::query()->find($this->versionId);

        if ($version === null) {
            // Deleted between queueing and running. The customer who deleted it is not
            // waiting for an answer.
            return;
        }

        if ($version->status !== DesignVersionStatus::Pending) {
            /*
             * Already running or already finished — a duplicate delivery, which every
             * queue driver produces eventually. Running it again would spend a second set
             * of provider calls and overwrite an image somebody may already be looking at.
             */
            return;
        }

        $pipeline->run($version);
    }

    /**
     * The worker itself died: a fatal error, a timeout, a deploy.
     *
     * The pipeline never got to write anything, so the version would sit at `generating`
     * forever and the credits would stay held until the sweeper found them. Both are
     * closed here, which is a worse outcome than success and a far better one than
     * silence.
     */
    public function failed(Throwable $e): void
    {
        $version = DesignVersion::query()->find($this->versionId);

        if ($version === null || $version->status->isTerminal()) {
            return;
        }

        app(DesignVersionTree::class)->markFailed(
            $version,
            'Tasarım üretimi beklenmedik şekilde sonlandı. Krediniz iade edildi.',
        );

        if ($version->credit_reservation_id === null) {
            return;
        }

        $reservation = CreditReservation::query()->find($version->credit_reservation_id);

        if ($reservation !== null && $reservation->isHeld()) {
            app(CreditLedger::class)->release($reservation, 'Tasarım üretilemedi');
        }
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['design', 'design-version:'.$this->versionId];
    }
}

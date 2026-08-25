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
     * Three model calls, each with its own retries.
     *
     * Generous on purpose: if the worker gives up first, the pipeline never writes the
     * failure, the version sits at `generating` forever and the customer watches a spinner
     * with no end — the exact state {@see failed()} exists to clean up and one worth not
     * reaching by construction.
     */
    public int $timeout = 900;

    public function __construct(public readonly string $versionId) {}

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

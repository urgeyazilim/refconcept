<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Jobs\GenerateDesignVideo;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\DesignVideo;
use Illuminate\Support\Facades\DB;

/**
 * Starting a film: what it costs, who pays, and getting it onto a worker.
 *
 * The same four steps in the same order as {@see DesignVersionLauncher}, and for the same
 * reasons: **refuse, then create, then hold, then queue.** Holding credits before the row
 * exists leaves a reservation pointing at nothing; queueing before the hold races a worker
 * against the customer's own balance.
 *
 * A film is the most expensive thing a customer can ask this platform for — about three
 * premium renders — so the refusals come first and are specific. "Bu tasarım henüz hazır
 * değil" and "zaten bir video hazırlanıyor" are both far better answers than a spinner.
 */
final class DesignVideoLauncher
{
    /**
     * The one camera move, written down.
     *
     * Supplied as a prompt variable rather than baked into the published prompt, because
     * offering a customer a choice of moves later must not require a new prompt version —
     * and a published version cannot be edited, by database trigger.
     *
     * **Forward, and then a turn.** The first version asked for a slow dolly with a slight
     * arc and produced a zoom: pulled apart frame by frame, the room at seven seconds was
     * the room at nought seconds and simply larger. Forward travel alone reads as a zoom
     * because the frame changes size without changing angle; the yaw partway through reveals
     * the wall the camera was facing, and that is the moment it stops being a picture and
     * becomes a space somebody is standing in.
     *
     * A lateral orbit travels further still and was rejected for it: tested on the same
     * render, it deleted both armchairs and the rug by the fifth second. Everything in this
     * film is meant to be something the customer can buy.
     */
    private const CAMERA_MOVE = 'The camera glides forward into the room past the nearest'
        .' seat, then turns to the right, panning across the seating group to reveal the wall'
        .' it was facing.';

    public function __construct(
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Creates a film, pays for it, and hands it to a worker.
     *
     * @throws DesignVersionRefused when there is nothing to film, or one is already running
     * @throws InsufficientCredits when the customer cannot pay for it
     */
    public function launch(DesignVersion $version, User $actor): DesignVideo
    {
        $this->assertFilmable($version);

        $cost = $this->quote();

        $video = DesignVideo::query()->create([
            'design_version_id' => $version->getKey(),
            'status' => DesignVersionStatus::Pending,
            'requested_by' => $actor->getKey(),
            'credit_cost' => $cost,
        ]);

        if ($cost > 0) {
            try {
                $reservation = $this->ledger->reserve(
                    user: $actor,
                    credits: $cost,
                    // The film's own id, so a retried request finds its hold rather than
                    // taking a second one.
                    reference: 'design-video:'.$video->getKey(),
                    description: 'Oda videosu',
                    subject: $video,
                    expiresAt: now()->addHours(2),
                );

                $video->forceFill(['credit_reservation_id' => $reservation->getKey()])->save();
            } catch (InsufficientCredits $e) {
                /*
                 * Marked failed rather than deleted, so the customer sees why on the design
                 * they were looking at instead of finding that their click did nothing.
                 */
                $video->forceFill([
                    'status' => DesignVersionStatus::Failed,
                    'failure_reason' => $e->getMessage(),
                    'completed_at' => now(),
                ])->save();

                throw $e;
            }
        }

        /*
         * Dispatched after the transaction commits. A worker is a separate process and can
         * pick the job up within milliseconds — before an uncommitted row is visible to it.
         */
        DB::afterCommit(static function () use ($video): void {
            GenerateDesignVideo::dispatch((string) $video->getKey());
        });

        return $video;
    }

    /**
     * What a film costs, before anything is created.
     *
     * Read from the route rather than held as a constant, so an operator who moves the task
     * onto a cheaper model reprices it without a deploy — and so the number on the button
     * is the number that will actually be charged.
     */
    public function quote(): int
    {
        $route = AiTaskRoute::query()
            ->where('task', AiTask::VideoTour->value)
            ->first();

        return (int) ($route->credit_cost ?? 0);
    }

    /** What the camera is told to do. */
    public function cameraMove(): string
    {
        return self::CAMERA_MOVE;
    }

    /**
     * Whether this design can be filmed at all, and why not.
     *
     * @throws DesignVersionRefused
     */
    private function assertFilmable(DesignVersion $version): void
    {
        if ($version->status !== DesignVersionStatus::Ready) {
            // There is no still to move the camera through. Given only words the model
            // composes its own room, and the customer is shown a tour of somewhere else.
            throw DesignVersionRefused::nothingToFilm();
        }

        $render = $version->assets->firstWhere('type', 'render');

        if ($render === null) {
            throw DesignVersionRefused::renderMissing();
        }

        $inFlight = DesignVideo::query()
            ->where('design_version_id', $version->getKey())
            ->whereIn('status', [DesignVersionStatus::Pending->value, DesignVersionStatus::Generating->value])
            ->exists();

        if ($inFlight) {
            /*
             * Checked here and enforced by a partial unique index underneath, because this
             * check and the insert are not one operation: two clicks a hundred milliseconds
             * apart both pass it, and only the index stops the second charge.
             */
            throw DesignVersionRefused::videoAlreadyRunning();
        }
    }
}

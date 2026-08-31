<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Models\DesignVideo;
use App\Domains\Projects\Services\DesignVideoLauncher;
use App\Domains\Projects\Services\RoomPhotoStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Films one design on a worker.
 *
 * A thin shell over the AI gateway, like every other generation job here. What is unusual
 * is how long it holds the worker: the provider answers with an operation rather than a
 * file, and the adapter polls it for a minute or two before there is anything to save. That
 * is why this runs on the AI queue, where the multi-minute renders already live, and why
 * the timeout is generous — a worker that gives up before the pipeline writes anything
 * leaves the customer watching a spinner that will never stop.
 *
 * `$tries = 1` for the same reason as the render: the gateway already retries the model
 * call with a policy that knows a timeout from a refusal. A queue retry on top would start
 * a *second* sixty-four-cent film for a failure the gateway had already decided was final.
 */
final class GenerateDesignVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Two attempts at a two-minute operation, plus the download, plus room to spare. */
    public int $timeout = 900;

    public function __construct(public readonly string $videoId)
    {
        $this->onQueue('ai');
    }

    public function handle(
        AiJobDispatcher $dispatcher,
        DesignVideoLauncher $launcher,
        RoomPhotoStorage $storage,
        CreditLedger $ledger,
    ): void {
        $video = DesignVideo::query()->with('version.assets')->find($this->videoId);

        if ($video === null) {
            // Deleted between queueing and running. Nobody is waiting for an answer.
            return;
        }

        if ($video->status !== DesignVersionStatus::Pending) {
            /*
             * Already running or already finished — a duplicate delivery, which every queue
             * driver produces eventually. Running it again would spend a second sixty-four
             * cents and overwrite a film somebody may already be watching.
             */
            return;
        }

        $version = $video->version;
        $render = $version?->assets->firstWhere('type', 'render');

        if ($version === null || $render === null) {
            $this->fail($video, 'Videonun üretileceği görsel bulunamadı.', $ledger);

            return;
        }

        $video->forceFill([
            'status' => DesignVersionStatus::Generating,
            'started_at' => now(),
        ])->save();

        $ran = $dispatcher->runInline(
            task: AiTask::VideoTour,
            input: [
                /*
                 * The render is the first frame, so the film starts from the room the
                 * customer has already approved. Sent as a disk reference rather than a
                 * link: a signed URL to somebody's home must not leave this system, and the
                 * adapter reads the bytes inside our own network either way.
                 */
                'image_sources' => [[
                    'disk' => $render->disk,
                    'path' => $render->storage_path,
                ]],
                'camera_move' => $launcher->cameraMove(),
                'style' => $version->style_code ?? $version->plan->style ?? 'modern',
                'room_type' => $version->design?->room?->room_type->value ?? 'living_room',
                // Provider knobs the gateway passes through untouched. Sixteen by nine
                // because that is the shape of every screen this will be watched on.
                'options' => ['aspect_ratio' => '16:9', 'resolution' => '1080p'],
            ],
            user: $video->requester,
            subject: $video,
            // Already held by the launcher, at the moment the customer pressed the button.
            // Charging again here would take the price twice for one film.
            creditCostOverride: 0,
        );

        $video->forceFill(['ai_job_id' => $ran->getKey()])->save();

        if ($ran->status !== AiJobStatus::Succeeded) {
            $this->fail($video, $ran->failure_reason ?? 'Video üretilemedi.', $ledger);

            return;
        }

        /** @var array<int, string> $refs */
        $refs = (array) ($ran->output['image_refs'] ?? []);

        if ($refs === []) {
            // The call succeeded, the money was spent, and there is no file. A different
            // problem from a provider failure, and worth its own sentence.
            $this->fail($video, 'Sağlayıcı bir video döndürmedi.', $ledger);

            return;
        }

        try {
            $asset = $storage->storeRenderFromRef((string) $version->getKey(), $refs[0], 'video');
        } catch (Throwable $e) {
            $this->fail($video, 'Video kaydedilemedi: '.$e->getMessage(), $ledger);

            return;
        }

        $video->forceFill([
            'status' => DesignVersionStatus::Ready,
            'asset_id' => $asset->getKey(),
            'completed_at' => now(),
        ])->save();

        $this->settle($video, $ledger);
    }

    /**
     * The worker itself died: a fatal error, a timeout, a deploy.
     *
     * Nothing else would ever write the failure, so the film would sit at `generating`
     * forever and the credits would stay held until the sweeper found them. Both are closed
     * here — a worse outcome than success, and a far better one than silence.
     */
    public function failed(Throwable $e): void
    {
        $video = DesignVideo::query()->find($this->videoId);

        if ($video === null || $video->status->isTerminal()) {
            return;
        }

        $this->fail(
            $video,
            'Video üretimi beklenmedik şekilde sonlandı. Krediniz iade edildi.',
            app(CreditLedger::class),
        );
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['design', 'design-video:'.$this->videoId];
    }

    // --- internals -----------------------------------------------------------

    private function fail(DesignVideo $video, string $reason, CreditLedger $ledger): void
    {
        $video->forceFill([
            'status' => DesignVersionStatus::Failed,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ])->save();

        $this->release($video, $ledger);
    }

    /** A film that was made is a film that was paid for. */
    private function settle(DesignVideo $video, CreditLedger $ledger): void
    {
        $reservation = $this->reservationOf($video);

        if ($reservation !== null && $reservation->isHeld()) {
            $ledger->consume($reservation, 'Oda videosu üretildi');
        }
    }

    /** A film that was not made costs nothing. */
    private function release(DesignVideo $video, CreditLedger $ledger): void
    {
        $reservation = $this->reservationOf($video);

        if ($reservation !== null && $reservation->isHeld()) {
            $ledger->release($reservation, 'Video üretilemedi');
        }
    }

    private function reservationOf(DesignVideo $video): ?CreditReservation
    {
        if ($video->credit_reservation_id === null) {
            return null;
        }

        return CreditReservation::query()->find($video->credit_reservation_id);
    }
}

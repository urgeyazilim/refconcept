<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Projects\Exceptions\DesignGenerationFailed;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Services\RoomAnalyser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reads a room's photographs in the background, once the set of them has settled.
 *
 * Queued after every photograph upload with a short delay, and when the customer asks. A
 * customer uploading four corners of a room in a row would otherwise pay for four readings
 * of which three are already stale by the time they run — so the job carries the set it was
 * queued for and does nothing if the room has moved on, leaving the reading to the job
 * queued after the last upload.
 */
final class AnalyseRoom implements ShouldQueue
{
    use Queueable;

    /** Once. A reading that failed is a room the design pipeline reads again on demand. */
    public int $tries = 1;

    public int $timeout = 300;

    /** @param  list<string>  $photoIds  the photographs this job was queued to read */
    public function __construct(
        public readonly string $roomId,
        public readonly array $photoIds,
        public readonly bool $force = false,
    ) {
        $this->onQueue('ai');
    }

    public function handle(RoomAnalyser $analyser): void
    {
        $room = Room::query()->find($this->roomId);

        if ($room === null) {
            return;
        }

        // The photographs changed since this was queued: a later job has the current set.
        if ($analyser->photoIds($room) !== $this->photoIds) {
            return;
        }

        if (! $this->force && $analyser->currentFor($room) !== null) {
            return;
        }

        try {
            $analyser->forRoom($room, refresh: true);
        } catch (DesignGenerationFailed|AiJobRefused $e) {
            /*
             * Said in the log, not thrown: nobody is waiting on this, and the pipeline reads
             * the room again itself when a design asks for it. On a synchronous queue this
             * runs inside the upload request, and a reading that cannot run must never turn
             * a successful upload into a 503.
             */
            Log::warning('Oda tanıma arka planda başarısız oldu.', ['room_id' => $this->roomId, 'reason' => $e->getMessage()]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomClearer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Empties a room photograph in the background.
 *
 * Queued when the customer asks for it and, later, when a photograph is analysed. Nobody
 * waits on it: the studio shows the photograph until the plate arrives and swaps it in.
 */
final class ClearRoomPhotograph implements ShouldQueue
{
    use Queueable;

    /** Once. A plate that failed is a room drawn on its photograph, which is what it had. */
    public int $tries = 1;

    public int $timeout = 300;

    /** @param  list<string>  $keep  what the customer asked to leave in the room */
    public function __construct(public readonly string $mediaId, public readonly array $keep = [])
    {
        $this->onQueue('ai');
    }

    public function handle(RoomClearer $clearer): void
    {
        $photograph = RoomMedia::query()->with('room')->find($this->mediaId);

        if ($photograph === null || $photograph->type !== 'photo' || $photograph->room === null) {
            return;
        }

        $clearer->clear($photograph->room, $photograph, $this->keep);
    }
}

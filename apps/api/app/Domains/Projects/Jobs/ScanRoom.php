<?php

declare(strict_types=1);

namespace App\Domains\Projects\Jobs;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomMedia;
use App\Domains\Projects\Services\RoomAnalyser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Measures a room's shape from every photograph of it at once.
 *
 * The reading estimates a room by reasoning about one picture — door heights, tile widths —
 * and it is honest about being an estimate. This triangulates from all of them, which is the
 * only way to tell a long room from a square one: on the product owner's own living room the
 * reading answered 3.8 by 4.5 metres one time and 4.5 by 5.0 the next, and the room is
 * neither.
 *
 * **Asked for, never automatic.** It costs money per room, and the first real attempt came
 * back as a cloud with no walls in it. Nothing here runs unless somebody pressed a button,
 * and the route it uses is paused until somebody decides it is worth keeping.
 *
 * What comes back is a shape, not a size. The reconstruction is faithful about proportion and
 * silent about scale, so this stores the cloud for the customer to look at and leaves the
 * measurements alone — a number derived from it would look precise and would not be.
 */
final class ScanRoom implements ShouldQueue
{
    use Queueable;

    /**
     * Once.
     *
     * A reconstruction that failed is a room without one, which is the ordinary state of
     * every room. Retrying a paid call because a provider was briefly unhappy is paying
     * twice for the same cloud.
     */
    public int $tries = 1;

    /** Four minutes of work and a four-megabyte download; the worker's own limit is higher. */
    public int $timeout = 900;

    public function __construct(public readonly string $roomId)
    {
        /*
         * The mesh queue, not the AI one.
         *
         * This takes minutes and the AI worker runs one job at a time: a scan on that queue
         * would hold up the reading of somebody else's room, which is the mistake the models
         * queue was created to stop.
         */
        $this->onQueue('models');
    }

    public function handle(AiJobDispatcher $dispatcher, GeneratedImageStore $files, RoomAnalyser $analyser): void
    {
        $room = Room::query()->find($this->roomId);

        if ($room === null) {
            return;
        }

        $photographs = $analyser->photographs($room);

        if (count($photographs) < 2) {
            Log::info('Oda taraması atlandı: yeterli fotoğraf yok.', ['room_id' => $this->roomId]);

            return;
        }

        /*
         * The bytes, not a link.
         *
         * A signed link to a customer's room photograph must not leave this system, and one
         * signed for the browser's host cannot be fetched from where the model runs anyway.
         * The adapter puts these on fal's own storage and hands the model those links.
         */
        $images = [];

        foreach ($photographs as $photo) {
            $bytes = $this->bytes($photo);

            if ($bytes !== null) {
                $images[] = 'data:'.$photo->mime_type.';base64,'.base64_encode($bytes);
            }
        }

        if (count($images) < 2) {
            Log::warning('Oda taraması atlandı: fotoğraflar okunamadı.', ['room_id' => $this->roomId]);

            return;
        }

        try {
            $ran = $dispatcher->runInline(
                task: AiTask::RoomScan,
                input: [
                    'room_id' => (string) $room->getKey(),
                    'image_urls' => $images,
                    // Nothing for the gateway to fetch: the adapter sends the bytes itself.
                    'image_sources' => [],
                ],
                subject: $room,
                /*
                 * One scan per set of photographs.
                 *
                 * Pressing the button twice on the same room is the same question, and the
                 * answer is already on disk. Adding a photograph changes the key, which is
                 * exactly when asking again is worth paying for.
                 */
                idempotencyKey: 'room-scan:'.hash('sha256', implode(',', $analyser->photoIds($room))),
                creditCostOverride: 0,
            );
        } catch (AiJobRefused $e) {
            // The route is paused or unrouted — an operator's decision, not a fault.
            Log::info('Oda taraması reddedildi.', ['room_id' => $this->roomId, 'reason' => $e->getMessage()]);

            return;
        } catch (Throwable $e) {
            Log::warning('Oda taraması başarısız.', ['room_id' => $this->roomId, 'reason' => $e->getMessage()]);

            return;
        }

        if ($ran->status !== AiJobStatus::Succeeded) {
            Log::info('Oda taraması tamamlanamadı.', [
                'room_id' => $this->roomId,
                'reason' => $ran->failure_kind?->value,
            ]);

            return;
        }

        /** @var array<int, string> $refs */
        $refs = (array) ($ran->output['image_refs'] ?? []);
        $reference = $refs[0] ?? null;

        if (! is_string($reference) || ! $files->exists($reference)) {
            return;
        }

        $bytes = $files->read($reference);

        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }

        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        $this->store($room, $photographs[0], $bytes);
        $files->discard($reference);
    }

    /** The photograph's own bytes, or null when the disk will not give them up. */
    private function bytes(RoomMedia $media): ?string
    {
        try {
            $bytes = Storage::disk($media->disk)->get($media->storage_path);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Keeps the cloud beside the photographs it was made from, under the same rules.
     *
     * Private disk, random key, no URL in any response — a point cloud of somebody's living
     * room is their home as surely as a picture of it is. The previous one is replaced: a
     * room has one shape, and two clouds in a list is a question nobody asked.
     */
    private function store(Room $room, RoomMedia $beside, string $bytes): void
    {
        $room->media()->where('type', 'scan')->get()->each(function (RoomMedia $old): void {
            try {
                Storage::disk($old->disk)->delete($old->storage_path);
            } catch (Throwable) {
                // The row goes either way: a file nobody can reach is worse than an orphan.
            }

            $old->delete();
        });

        $path = 'room-media/'.$room->getKey().'/'.Str::uuid7().'.glb';

        Storage::disk($beside->disk)->put($path, $bytes);

        RoomMedia::query()->create([
            'room_id' => $room->getKey(),
            'type' => 'scan',
            'disk' => $beside->disk,
            'storage_path' => $path,
            'original_name' => 'oda-taramasi.glb',
            'mime_type' => 'model/gltf-binary',
            'size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
            'position' => 100,
        ]);
    }
}

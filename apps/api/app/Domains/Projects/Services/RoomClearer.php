<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes the furniture out of a room photograph.
 *
 * The result is the plate: the customer's own walls, floor, windows and doors with nothing
 * standing in front of them, which is what every render of that room then starts from. A
 * render built on the furnished photograph has to paint over the customer's old sofa and
 * usually paints around it instead; a render built on the plate has nothing to paint over.
 *
 * Rule K8 of the studio contract: movable things go, architecture stays. The analysis's own
 * list of what it saw standing in the room is handed to the model by name, so "remove the
 * furniture" is "remove the two armchairs, the coffee table and the rug" rather than a guess.
 *
 * Rule K10: once per photograph. The idempotency key is the photograph, so a room opened
 * twice does not pay twice.
 */
final class RoomClearer
{
    public function __construct(
        private readonly AiJobDispatcher $dispatcher,
        private readonly GeneratedImageStore $files,
        private readonly RoomPhotoStorage $storage,
    ) {}

    /**
     * Makes the plate, or returns the one already made.
     *
     * Null when it could not be made — the task is paused, the provider failed, or the
     * simulator answered — and the room carries on with its photograph, which is what it had.
     */
    public function clear(Room $room, RoomMedia $photograph): ?RoomMedia
    {
        $existing = $this->storage->plateOf($photograph);

        if ($existing !== null) {
            return $existing;
        }

        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->where('media_id', $photograph->getKey())
            ->where('is_current', true)
            ->latest('created_at')
            ->first();

        try {
            $ran = $this->dispatcher->runInline(
                task: AiTask::RoomClear,
                input: [
                    'room_type' => $analysis->detected_room_type ?? $room->room_type->value,
                    'objects' => $this->objectsSeenIn($analysis),
                    // Read off the disk and sent as bytes. The photograph never leaves as a link.
                    'image_sources' => [['disk' => $photograph->disk, 'path' => $photograph->storage_path]],
                    'image_roles' => ['0: the room photograph to empty'],
                ],
                subject: $room,
                idempotencyKey: 'room-plate:'.$photograph->getKey(),
                creditCostOverride: 0,
            );
        } catch (Throwable $e) {
            Log::info('Oda boşaltılamadı.', ['room' => $room->getKey(), 'reason' => $e->getMessage()]);

            return null;
        }

        if ($ran->status !== AiJobStatus::Succeeded) {
            Log::info('Oda boşaltılamadı.', ['room' => $room->getKey(), 'reason' => $ran->failure_kind?->value]);

            return null;
        }

        /*
         * A simulator's answer is not a plate.
         *
         * With no key on file the task routes to the fake provider, which succeeds and hands
         * back a placeholder picture. Storing that would put a grey rectangle where the
         * customer's room should be, in every render from then on.
         */
        $answeredBySimulator = $ran->requests()
            ->with('model.provider')
            ->latest('attempt')
            ->first()
            ?->model?->provider?->driver === 'fake';

        if ($answeredBySimulator) {
            return null;
        }

        /** @var array<int, string> $refs */
        $refs = (array) ($ran->output['image_refs'] ?? []);

        $reference = $refs[0] ?? null;

        if (! is_string($reference) || ! $this->files->exists($reference)) {
            return null;
        }

        return $this->storage->storePlate($room, $photograph, $reference);
    }

    /**
     * What the analysis saw standing in the room, as a sentence the model can act on.
     *
     * "all movable furniture and objects" when nothing was listed — the instruction still
     * has to say what to remove.
     */
    private function objectsSeenIn(?RoomAnalysis $analysis): string
    {
        $objects = (array) ($analysis?->payload['movable_objects'] ?? []);

        $names = [];

        foreach ($objects as $object) {
            $type = is_array($object) ? ($object['type'] ?? null) : $object;

            if (is_string($type) && $type !== '') {
                $names[] = $type;
            }
        }

        $names = array_values(array_unique($names));

        return $names === []
            ? 'all movable furniture and objects'
            : implode(', ', $names).' and any other movable furniture or objects';
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Projects\Exceptions\DesignGenerationFailed;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use Illuminate\Support\Facades\DB;

/**
 * Reads a room photograph into something the planner can work with.
 *
 * The first step of every generation, and the one most worth not repeating. A room does
 * not change because somebody tried a second style, so an analysis is cached against the
 * photograph rather than the design — the second render of the same room reuses the first
 * reading, which is one fewer provider call and one fewer thing to go wrong.
 *
 * The photograph itself never enters a prompt as text. It travels as an attachment on the
 * job, because a URL written into a prompt is a URL a model can repeat back inside an
 * answer somebody else reads, and this one points at the inside of a customer's home.
 */
final class RoomAnalyser
{
    public function __construct(
        private readonly AiJobDispatcher $dispatcher,
        private readonly RoomPhotoStorage $storage,
    ) {}

    /**
     * The current analysis for a room, reading it first if there is not one.
     *
     * @throws DesignGenerationFailed
     */
    public function forRoom(Room $room, bool $refresh = false): RoomAnalysis
    {
        $room->loadMissing('primaryMedia');

        $media = $room->primaryMedia;

        if ($media === null) {
            throw DesignGenerationFailed::roomHasNoPhotograph();
        }

        if (! $refresh) {
            $existing = RoomAnalysis::query()
                ->where('room_id', $room->getKey())
                ->where('media_id', $media->getKey())
                ->current()
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        /*
         * A signed link that outlives the call but not the day. The provider fetches the
         * image itself rather than receiving the bytes, so this process does not hold a
         * room photograph in memory on a request somebody is waiting on.
         */
        $imageUrl = $this->storage->temporaryUrl($media);

        $ran = $this->dispatcher->runInline(
            task: AiTask::RoomAnalysis,
            input: [
                'room_type' => $room->room_type->value,
                'notes' => $room->notes,
                'dimensions' => array_filter([
                    'width_mm' => $room->width_mm,
                    'length_mm' => $room->length_mm,
                    'height_mm' => $room->height_mm,
                ]),
                'image_urls' => [$imageUrl],
            ],
            subject: $room,
            // Billed to the design version that asked for it, not separately. A customer
            // pays for a design, not for the steps inside one.
            creditCostOverride: 0,
        );

        if ($ran->status !== AiJobStatus::Succeeded) {
            throw DesignGenerationFailed::analysisFailed(
                $ran->failure_kind?->label() ?? 'Bilinmeyen hata',
            );
        }

        return $this->store($room, $media->getKey(), (string) $ran->getKey(), (array) ($ran->output['structured'] ?? []));
    }

    /**
     * Writes an analysis and demotes the one it replaces.
     *
     * In one transaction because a partial unique index enforces that a room has exactly
     * one current analysis: demoting and inserting apart would leave a window in which
     * the insert fails, and the room would be left with no current reading at all.
     *
     * @param  array<string, mixed>  $structured
     */
    public function store(Room $room, string $mediaId, ?string $jobId, array $structured): RoomAnalysis
    {
        return DB::transaction(function () use ($room, $mediaId, $jobId, $structured): RoomAnalysis {
            RoomAnalysis::query()
                ->where('room_id', $room->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false]);

            return RoomAnalysis::query()->create([
                'room_id' => $room->getKey(),
                'media_id' => $mediaId,
                'ai_job_id' => $jobId,
                'detected_room_type' => $this->stringOrNull($structured['room_type'] ?? null),
                // A confidence of 0.94 becomes 9400 basis points. A float beside a price
                // is how the price becomes a float.
                'confidence_bps' => $this->confidenceToBps($structured['confidence'] ?? null),
                'measurement_quality' => $this->stringOrNull($structured['measurement_quality'] ?? null),
                'payload' => $structured,
                'fixed_elements' => $this->arrayOrNull($structured['fixed_elements'] ?? null),
                'surfaces' => $this->arrayOrNull($structured['surfaces'] ?? null),
                'warnings' => $this->arrayOrNull($structured['warnings'] ?? null),
                'is_current' => true,
            ]);
        });
    }

    private function confidenceToBps(mixed $confidence): ?int
    {
        if (! is_int($confidence) && ! is_float($confidence)) {
            return null;
        }

        // Clamped rather than trusted. A model that answers 1.4 has not become more
        // certain than certain, and a value outside the range would fail a CHECK.
        return max(0, min(10_000, (int) round((float) $confidence * 10_000)));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Exceptions\DesignGenerationFailed;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomMedia;
use Illuminate\Support\Facades\DB;

/**
 * Reads a room's photographs into something the planner can work with.
 *
 * All of them, the primary one first. A customer who walks round their room and photographs
 * it from every corner has told us more than one picture can, and an analysis that only
 * looked at the first was answering a different question than the one they asked. The model
 * is told they are views of the same room, so a window seen twice is one window.
 *
 * The first step of every generation, and the one most worth not repeating. A room does
 * not change because somebody tried a second style, so an analysis is cached against the
 * set of photographs it read rather than the design — the second render of the same room
 * reuses the first reading, and a new photograph makes it read again.
 *
 * The photographs never enter a prompt as text. They travel as attachments on the job,
 * because a URL written into a prompt is a URL a model can repeat back inside an answer
 * somebody else reads, and these point at the inside of a customer's home.
 */
final class RoomAnalyser
{
    /** How many photographs go to the model. Beyond this, more views add cost, not walls. */
    public const MAX_PHOTOS = 6;

    public function __construct(
        private readonly AiJobDispatcher $dispatcher,
        private readonly RoomGeometryProposer $proposer,
        private readonly PrimaryPhotoChooser $primaries,
    ) {}

    /**
     * The photographs an analysis reads, primary first, then in gallery order.
     *
     * @return list<RoomMedia>
     */
    public function photographs(Room $room): array
    {
        $photos = $room->media()
            ->where('type', 'photo')
            ->orderBy('position')
            ->get()
            ->sortBy(fn (RoomMedia $media): int => $media->getKey() === $room->primary_media_id ? 0 : 1)
            ->values()
            ->take(self::MAX_PHOTOS);

        return $photos->all();
    }

    /**
     * The ids of the photographs an analysis would read now.
     *
     * @return list<string>
     */
    public function photoIds(Room $room): array
    {
        return array_map(static fn (RoomMedia $media): string => (string) $media->getKey(), $this->photographs($room));
    }

    /** The current analysis, if it read the photographs the room has now. */
    public function currentFor(Room $room): ?RoomAnalysis
    {
        $analysis = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->current()
            ->first();

        if ($analysis === null) {
            return null;
        }

        return $this->readPhotoIds($analysis) === $this->photoIds($room) ? $analysis : null;
    }

    /**
     * What an analysis read, as recorded on it.
     *
     * Older analyses recorded only the primary photograph; they count as having read that.
     *
     * @return list<string>
     */
    public function readPhotoIds(RoomAnalysis $analysis): array
    {
        $ids = $analysis->payload['photo_ids'] ?? null;

        if (is_array($ids) && $ids !== []) {
            return array_values(array_map('strval', $ids));
        }

        return [(string) $analysis->media_id];
    }

    /**
     * The current analysis for a room, reading it first if there is not one for its photographs.
     *
     * @throws DesignGenerationFailed when there is no photograph or the reading failed
     * @throws AiJobRefused when the task cannot run at all — no route, no credential
     */
    public function forRoom(Room $room, bool $refresh = false): RoomAnalysis
    {
        $photos = $this->photographs($room);

        if ($photos === []) {
            throw DesignGenerationFailed::roomHasNoPhotograph();
        }

        if (! $refresh) {
            $existing = $this->currentFor($room);

            if ($existing !== null) {
                return $existing;
            }
        }

        /*
         * References to the objects, not links to them.
         *
         * The gateway reads the bytes off the disk and sends them inline. A signed URL was
         * the original design and it was wrong twice: a link to somebody's room photograph
         * must not leave this system, and the provider cannot fetch one from our network
         * regardless.
         */
        $sources = array_map(
            static fn (RoomMedia $media): array => ['disk' => $media->disk, 'path' => $media->storage_path],
            $photos,
        );

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
                'photo_count' => count($photos),
                'photo_note' => count($photos) === 1
                    ? 'Tek fotoğraf var.'
                    : sprintf('Aynı odanın %d fotoğrafı var; ilki ana fotoğraftır. Hepsini birleştirerek tek bir oda tanımı çıkar: aynı pencereyi ya da kapıyı iki kez sayma; regions yalnızca ilk fotoğraf için ver.', count($photos)),
                'image_sources' => $sources,
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

        $primary = $photos[0];

        return $this->store(
            $room,
            (string) $primary->getKey(),
            (string) $ran->getKey(),
            (array) ($ran->output['structured'] ?? []),
            array_map(static fn (RoomMedia $media): string => (string) $media->getKey(), $photos),
        );
    }

    /**
     * Writes an analysis and demotes the one it replaces.
     *
     * In one transaction because a partial unique index enforces that a room has exactly
     * one current analysis: demoting and inserting apart would leave a window in which
     * the insert fails, and the room would be left with no current reading at all.
     *
     * @param  array<string, mixed>  $structured
     * @param  list<string>  $photoIds  what was read, so a new photograph is noticed
     */
    public function store(Room $room, string $mediaId, ?string $jobId, array $structured, array $photoIds = []): RoomAnalysis
    {
        return DB::transaction(function () use ($room, $mediaId, $jobId, $structured, $photoIds): RoomAnalysis {
            RoomAnalysis::query()
                ->where('room_id', $room->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $analysis = RoomAnalysis::query()->create([
                'room_id' => $room->getKey(),
                'media_id' => $mediaId,
                'ai_job_id' => $jobId,
                // Forty characters, and a model that answers with a sentence would take the
                // whole reading down with it.
                'detected_room_type' => $this->fitted($structured['room_type'] ?? null, 40),
                // A confidence of 0.94 becomes 9400 basis points. A float beside a price
                // is how the price becomes a float.
                'confidence_bps' => $this->confidenceToBps($structured['confidence'] ?? null),
                'measurement_quality' => $this->quality($structured['measurement_quality'] ?? null),
                'payload' => $structured + ['photo_ids' => $photoIds === [] ? [$mediaId] : $photoIds],
                'fixed_elements' => $this->arrayOrNull($structured['fixed_elements'] ?? null),
                'surfaces' => $this->arrayOrNull($structured['surfaces'] ?? null),
                'warnings' => $this->arrayOrNull($structured['warnings'] ?? null),
                'is_current' => true,
            ]);

            /*
             * The measurements the photograph suggested, written down as something to agree
             * to rather than something to use. Inside the same transaction, because an
             * analysis stored without its proposal is a room the plan screen opens on a
             * blank form and a customer who is asked to measure a room we just measured.
             */
            $analysis->setRelation('room', $room);
            $this->proposer->propose($analysis);

            /*
             * Now that the room has been read, the best photograph to draw it from can be
             * chosen properly: the reading says which corner caught the window, the door and
             * the radiator, and that is the corner that shows the room. Skipped for a customer
             * who has already chosen one themselves.
             */
            $this->primaries->choose($room);

            return $analysis;
        });
    }

    /**
     * How the measurements were arrived at, as one of the words that column holds.
     *
     * The column is a twenty-character enum and the reading is a model answering in prose.
     * GPT-6 Astra put a whole sentence here — "Standart iç kapı boyutları ve dört fotoğrafın
     * birlikte değerlendirilmesine dayalı, düşük güvenli yaklaşık ölçülendirme" — the insert
     * was refused, the transaction rolled back, and a reading that had already been made and
     * paid for disappeared. The customer watched "odanı okuyorum" until they gave up and
     * asked for two more readings of a room that had been read three times.
     *
     * Not truncated, because the first twenty characters of a sentence is not a category —
     * it is a category nobody can look up. An answer that is not one of the words means the
     * question was not understood, and the honest record of that is nothing. The prose
     * itself is kept: the whole structured answer goes into `payload` untouched.
     */
    private function quality(mixed $value): ?string
    {
        return is_string($value)
            ? MeasurementQuality::tryFrom(strtolower(trim($value)))?->value
            : null;
    }

    /**
     * A string the column can hold, or nothing.
     *
     * Same reasoning as {@see quality()} and a different shape: a room type is free text
     * rather than an enum, so something too long is more likely a model being expansive than
     * a model misunderstanding — and the first forty characters of "living room with a
     * dining area" is still a room type. Longer than that and it is a paragraph, which is
     * not.
     */
    private function fitted(mixed $value, int $limit): ?string
    {
        $text = $this->stringOrNull($value);

        if ($text === null) {
            return null;
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit);
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

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Enums\OpeningVariant;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;

/**
 * Turns what the photograph said into measurements somebody can be asked about.
 *
 * A proposal, never a fact. The analysis estimates a room's size from a doorway and a floor
 * tile, which is how a person would do it too and is right to within a hand's width most of
 * the time and wrong by half a metre occasionally. Everything downstream rests on these
 * numbers, so they are written down as something to agree to rather than something to use.
 *
 * The openings ride along on the proposal rather than being created as constraints straight
 * away. A door detected in a photograph and silently added to the customer's list of fixed
 * elements is a door they did not put there and will not think to check; adopted at the
 * moment they confirm the measurements, it is part of one decision they actually made.
 */
final class RoomGeometryProposer
{
    /**
     * The bounds the table's CHECK enforces, mirrored so a bad estimate is dropped rather
     * than raising a constraint violation from inside a queued job.
     */
    private const MIN_MM = 1_000;

    private const MAX_MM = 30_000;

    /**
     * Records the analysis's measurements as an unconfirmed version, if it gave any.
     *
     * Returns null when there is nothing worth proposing — no estimate, an implausible one,
     * or a room whose measurements the customer has already agreed to. That last case is
     * deliberate: re-analysing a photograph should not put a question back in front of
     * somebody who has answered it.
     */
    public function propose(RoomAnalysis $analysis): ?RoomGeometryVersion
    {
        $room = $analysis->room;

        if ($room === null) {
            return null;
        }

        $estimate = $analysis->payload['estimated_dimensions'] ?? null;

        if (! is_array($estimate)) {
            return null;
        }

        $width = $this->plausible($estimate['width_mm'] ?? null);
        $length = $this->plausible($estimate['length_mm'] ?? null);
        $height = $this->plausible($estimate['height_mm'] ?? null);

        if ($width === null || $length === null || $height === null) {
            return null;
        }

        $confirmed = RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->where('is_confirmed', true)
            ->exists();

        if ($confirmed) {
            /*
             * The size is settled, but the doors and windows a later reading found are still
             * worth having: a customer who agreed to the size before the reading had placed
             * the door would otherwise be left with a sealed box and a door in a photograph.
             * Only into a room that has none of its own, as always.
             */
            $this->adopt($room, $this->openings($analysis));

            return null;
        }

        // One proposal per analysis. Re-reading the same photograph produces the same answer
        // and a second row nobody would be able to tell from the first.
        $existing = RoomGeometryVersion::query()
            ->where('room_id', $room->getKey())
            ->where('analysis_id', $analysis->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $openings = $this->openings($analysis);

        /*
         * Put on the walls now, not when the size is agreed to.
         *
         * They used to be carried in the proposal and written only on confirmation, so that
         * nothing appeared in a customer's room that they had not been shown. In practice it
         * did the opposite: the reading found a three-metre window and a door, the room step
         * drew an empty box, and the panel beside it said "Fotoğraftan kapı ya da pencere
         * çıkaramadım" about a reading that had found both. The product owner's answer was
         * the right one — the photograph is taken so that nobody has to do this by hand.
         *
         * Nothing is silent about it. This is the step whose whole job is to ask "Doğru mu?",
         * the openings are on the walls in front of that question, each is marked as the
         * photograph's rather than the customer's, and any of them can be dragged, retyped or
         * removed in a tap. Only ever into a room that has none of its own.
         */
        $this->adopt($room, $openings);

        return RoomGeometryVersion::query()->create([
            'room_id' => $room->getKey(),
            'version' => ((int) RoomGeometryVersion::query()->where('room_id', $room->getKey())->max('version')) + 1,
            'source' => 'ai',
            'width_mm' => $width,
            'length_mm' => $length,
            'height_mm' => $height,
            'confidence_bps' => $this->confidenceToBps($estimate['confidence'] ?? null)
                ?? $analysis->confidence_bps,
            'analysis_id' => $analysis->getKey(),
            // Kept on the version as well as on the walls: it is the record of what this
            // particular reading saw, and adoptOpenings() still has something to work from
            // for a room that had openings of its own at the time and lost them since.
            'payload' => ['openings' => $openings],
        ]);
    }

    /**
     * Adopts the openings a confirmed proposal carried.
     *
     * Only into a room that has none of its own. A customer who has already written down
     * their window is not helped by a second one appearing beside it at slightly different
     * coordinates, and there is no way to tell from here which of the two is right.
     *
     * @return int how many were added
     */
    public function adoptOpenings(RoomGeometryVersion $version): int
    {
        $room = $version->room;

        if ($room === null) {
            return 0;
        }

        $openings = $version->payload['openings'] ?? null;

        if (! is_array($openings) || $openings === []) {
            return 0;
        }

        return $this->adopt($room, $openings);
    }

    /**
     * Writes openings into a room that has none, as constraints the plan can draw.
     *
     * @param  list<array<string, mixed>>  $openings
     * @return int how many were added
     */
    private function adopt(Room $room, array $openings): int
    {
        if ($openings === []) {
            return 0;
        }

        $has = RoomConstraint::query()
            ->where('room_id', $room->getKey())
            ->whereIn('type', [ConstraintType::Door->value, ConstraintType::Window->value, ConstraintType::BalconyDoor->value])
            ->exists();

        if ($has) {
            return 0;
        }

        $added = 0;

        foreach ($openings as $opening) {
            if (! is_array($opening)) {
                continue;
            }

            $type = ConstraintType::tryFrom((string) ($opening['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            RoomConstraint::query()->create([
                'room_id' => $room->getKey(),
                'type' => $type,
                // The reading is not asked which kind; the width says. A 2.1 m window is three
                // panes, a 1.6 m door two leaves, and the customer can correct it in a tap.
                'variant' => OpeningVariant::guess($type, $opening['width_mm'] ?? null, $opening['sill_height_mm'] ?? null)?->value,
                'wall' => $opening['wall'],
                'offset_mm' => $opening['offset_mm'],
                'width_mm' => $opening['width_mm'],
                'height_mm' => $opening['height_mm'],
                'sill_height_mm' => $opening['sill_height_mm'] ?? null,
                'is_blocking' => $type->blocksByDefault(),
                'must_stay_visible' => $type->mustStayVisibleByDefault(),
                // Said plainly, because the customer did not write this down and should be
                // able to see at a glance which entries they did.
                'notes' => 'Fotoğraftan tespit edildi.',
            ]);

            $added++;
        }

        return $added;
    }

    // --- internals -------------------------------------------------------------

    /**
     * The openings the analysis reported, keeping only the ones that can be placed.
     *
     * A window without a wall or an offset cannot be drawn anywhere, and a door two metres
     * wide on a wall three metres long is a reading of the photograph that went wrong. Both
     * are dropped here rather than becoming a hole in the wrong place in the customer's room.
     *
     * @return list<array<string, mixed>>
     */
    private function openings(RoomAnalysis $analysis): array
    {
        $reported = $analysis->payload['openings'] ?? null;

        if (! is_array($reported)) {
            return [];
        }

        $kept = [];

        foreach ($reported as $opening) {
            if (! is_array($opening)) {
                continue;
            }

            $wall = is_string($opening['wall'] ?? null) ? $opening['wall'] : null;
            $offset = is_int($opening['offset_mm'] ?? null) ? $opening['offset_mm'] : null;
            $width = is_int($opening['width_mm'] ?? null) ? $opening['width_mm'] : null;

            if ($wall === null || $offset === null || $width === null) {
                continue;
            }

            if (! in_array($wall, ['north', 'south', 'east', 'west'], true)) {
                continue;
            }

            if ($offset < 0 || $width < 200 || $width > 6_000) {
                continue;
            }

            $type = ConstraintType::tryFrom((string) ($opening['type'] ?? ''));

            $kept[] = [
                // Anything unrecognised is recorded as a window: it is the opening that only
                // asks for room in front of it, so a misreading costs a warning rather than
                // a refusal the customer cannot explain.
                'type' => ($type ?? ConstraintType::Window)->value,
                'wall' => $wall,
                'offset_mm' => $offset,
                'width_mm' => $width,
                'height_mm' => is_int($opening['height_mm'] ?? null) ? $opening['height_mm'] : null,
                'sill_height_mm' => is_int($opening['sill_height_mm'] ?? null) ? $opening['sill_height_mm'] : null,
            ];
        }

        return $kept;
    }

    private function plausible(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $mm = (int) round((float) $value);

        return $mm >= self::MIN_MM && $mm <= self::MAX_MM ? $mm : null;
    }

    private function confidenceToBps(mixed $confidence): ?int
    {
        if (! is_int($confidence) && ! is_float($confidence)) {
            return null;
        }

        // 0.94 becomes 9400 basis points, the same as everywhere else a rate is stored.
        return max(0, min(10_000, (int) round((float) $confidence * 10_000)));
    }
}

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
            $this->adopt($room, $this->openings($analysis, $width, $length, $height));

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

        $openings = $this->openings($analysis, $width, $length, $height);

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
        $this->adoptFixtures($room, $analysis);

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

        $kinds = [ConstraintType::Door->value, ConstraintType::Window->value, ConstraintType::BalconyDoor->value];

        /*
         * The customer's word stands. A reading may only replace a reading.
         *
         * This used to refuse whenever the room had any opening at all, which was right for
         * the reason it gave — a second window appearing beside the one somebody wrote down,
         * at slightly different coordinates, helps nobody — and wrong in what it checked. The
         * first reading puts a door and a window on the walls, and from that moment the room
         * *has* openings, so every later reading of the same photographs was discarded
         * whatever it found. The product owner asked for the room to be done again and it
         * came back identical, because nothing they could press would ever move a wall.
         *
         * Ownership, not existence. Anything the customer wrote, dragged or retyped is
         * theirs and is left alone — and if the room holds even one of those, the photograph
         * does not get to rewrite the room around it.
         */
        $own = RoomConstraint::query()
            ->where('room_id', $room->getKey())
            ->whereIn('type', $kinds)
            ->where('source', '!=', 'ai')
            ->exists();

        if ($own) {
            return 0;
        }

        // What the last reading put there, replaced rather than added to: it is the same
        // machine answering the same question, and two answers on one wall is not an answer.
        RoomConstraint::query()
            ->where('room_id', $room->getKey())
            ->whereIn('type', $kinds)
            ->where('source', 'ai')
            ->delete();

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
                /*
                 * What the reading said it is, and the width only when it did not say.
                 *
                 * The kind used to be deduced from the width alone, which made every 1.2 m
                 * opening a double casement whether the photograph showed two sashes or one
                 * tall pane — and the customer met a room drawn with the wrong window. The
                 * model is looking at the window and can say; the width stays as the answer
                 * for a reading that does not.
                 */
                'variant' => self::variantOf($type, $opening)?->value,
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
            ])->forceFill(['source' => 'ai'])->save();

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
    private function openings(RoomAnalysis $analysis, int $roomWidthMm, int $roomLengthMm, int $roomHeightMm): array
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

            if ($wall === null || ! in_array($wall, ['north', 'south', 'east', 'west'], true)) {
                continue;
            }

            $wallMm = $wall === 'north' || $wall === 'south' ? $roomWidthMm : $roomLengthMm;

            /*
             * Where along the wall, as the reading gave it.
             *
             * A proportion first, because that is the question a model looking at a wall can
             * actually answer — "this window starts about a third of the way along" — and the
             * millimetres follow from a wall whose length we already know. A reading from
             * before the prompt asked that way still answers in millimetres, and those are
             * still honoured.
             */
            $span = $this->along($opening, $wallMm);

            $offset = $span['offset'] ?? (is_int($opening['offset_mm'] ?? null) ? $opening['offset_mm'] : null);
            $width = $span['width'] ?? (is_int($opening['width_mm'] ?? null) ? $opening['width_mm'] : null);

            if ($offset === null || $width === null) {
                continue;
            }

            if ($offset < 0 || $width < 200 || $width > 6_000) {
                continue;
            }

            /*
             * It has to fit the wall it says it is on.
             *
             * The wall names are defined against the main photograph and the measurements are
             * defined against the wall names, so the two can disagree — and when they do, the
             * room is drawn with its proportions the wrong way round and a window hanging off
             * the end of a wall. A window wider than the wall it claims is a reading that went
             * wrong somewhere; keeping it would put a hole in a wall of somebody's room that
             * does not have one.
             */
            if ($width > $wallMm) {
                continue;
            }

            // Past the end but narrow enough to belong there: slid back onto the wall rather
            // than thrown away, because where it is is a guess and that it exists is not.
            $offset = min($offset, $wallMm - $width);

            $type = ConstraintType::tryFrom((string) ($opening['type'] ?? ''));

            $kept[] = [
                // Anything unrecognised is recorded as a window: it is the opening that only
                // asks for room in front of it, so a misreading costs a warning rather than
                // a refusal the customer cannot explain.
                'type' => ($type ?? ConstraintType::Window)->value,
                // Kept as the reading gave it, valid or not; variantOf() is what decides.
                'variant' => is_string($opening['variant'] ?? null) ? $opening['variant'] : null,
                'wall' => $wall,
                'offset_mm' => $offset,
                'width_mm' => $width,
                'height_mm' => $this->height($opening, $roomHeightMm)
                    ?? (is_int($opening['height_mm'] ?? null) ? $opening['height_mm'] : null),
                'sill_height_mm' => $this->sill($opening, $roomHeightMm)
                    ?? (is_int($opening['sill_height_mm'] ?? null) ? $opening['sill_height_mm'] : null),
            ];
        }

        return $kept;
    }

    /**
     * Where an opening sits along its wall, from the proportions the reading gave.
     *
     * Empty when it gave none, or gave a pair that is not a stretch of wall — the ends the
     * wrong way round, or the same point twice. Nothing is salvaged from half an answer: a
     * start with no end is not a window, and inventing the missing half is the habit this
     * whole change exists to stop.
     *
     * @param  array<string, mixed>  $opening
     * @return array{offset?: int, width?: int}
     */
    private function along(array $opening, int $wallMm): array
    {
        $from = $this->ratio($opening['starts_at'] ?? null);
        $to = $this->ratio($opening['ends_at'] ?? null);

        if ($from === null || $to === null || $to <= $from) {
            return [];
        }

        $offset = (int) round($from * $wallMm);
        $width = (int) round(($to - $from) * $wallMm);

        // An opening that came out as nothing is not an opening. The rest of the checks —
        // too narrow, too wide, past the end of the wall — are the caller's, unchanged.
        return $width < 1 ? [] : ['offset' => $offset, 'width' => $width];
    }

    /**
     * How tall an opening is, from the proportions the reading gave.
     *
     * @param  array<string, mixed>  $opening
     */
    private function height(array $opening, int $roomHeightMm): ?int
    {
        $sill = $this->ratio($opening['sill_ratio'] ?? null);
        $head = $this->ratio($opening['head_ratio'] ?? null);

        if ($sill === null || $head === null || $head <= $sill) {
            return null;
        }

        $height = (int) round(($head - $sill) * $roomHeightMm);

        return $height < 1 ? null : $height;
    }

    /**
     * How far off the floor an opening starts, from the proportion the reading gave.
     *
     * Only alongside a head: a sill on its own gives a window with a bottom and no top, and
     * the height would fall back to a millimetre guess the sill no longer agrees with.
     *
     * @param  array<string, mixed>  $opening
     */
    private function sill(array $opening, int $roomHeightMm): ?int
    {
        $sill = $this->ratio($opening['sill_ratio'] ?? null);

        if ($sill === null || $this->height($opening, $roomHeightMm) === null) {
            return null;
        }

        return (int) round($sill * $roomHeightMm);
    }

    /**
     * A proportion of a wall: a number from 0 to 1, or null for anything else.
     *
     * Integers are accepted because 0 and 1 are proportions and JSON does not know they were
     * meant as floats. Anything outside the range is a reading that misunderstood the
     * question, and a misunderstood proportion is worse than none: it would be multiplied by
     * a wall.
     */
    private function ratio(mixed $value): ?float
    {
        if (! is_float($value) && ! is_int($value)) {
            return null;
        }

        $ratio = (float) $value;

        return $ratio < 0.0 || $ratio > 1.0 ? null : $ratio;
    }

    /**
     * The other things fixed to the walls: radiators, columns, sconces, built-ins.
     *
     * The reading has always seen them — it listed two wall sconces on the north wall of the
     * product owner's room — and nothing was ever done with them beyond telling the renderer
     * not to paint over them. So they were invisible to the customer and invisible to the
     * arrangement: a bookcase could be planned across a radiator and nothing would object.
     *
     * Written without a position, because the reading is not asked for one. They show in the
     * room's list as "yerleşim için yeterli bilgi yok" until somebody says where on the wall
     * they are, which is a question worth asking of a radiator and not worth asking of a
     * skirting board — so trim is not on this list at all: it is drawn, not worked around.
     *
     * Only into a room that has none of its own, like the openings.
     */
    private function adoptFixtures(Room $room, RoomAnalysis $analysis): int
    {
        // The column when the reading filled it, the payload otherwise: they are written
        // together and either is the same list.
        $elements = $analysis->fixed_elements ?? ($analysis->payload['fixed_elements'] ?? []);

        if (! is_array($elements) || $elements === []) {
            return 0;
        }

        $openings = [ConstraintType::Door->value, ConstraintType::Window->value, ConstraintType::BalconyDoor->value];

        $has = RoomConstraint::query()
            ->where('room_id', $room->getKey())
            ->whereNotIn('type', $openings)
            ->exists();

        if ($has) {
            return 0;
        }

        $added = 0;

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $type = self::fixtureType(is_string($element['type'] ?? null) ? $element['type'] : '');

            if ($type === null) {
                continue;
            }

            $wall = is_string($element['wall'] ?? null) && in_array($element['wall'], ['north', 'south', 'east', 'west'], true)
                ? $element['wall']
                : null;

            RoomConstraint::query()->create([
                'room_id' => $room->getKey(),
                'type' => $type,
                'wall' => $wall,
                'label' => is_string($element['label'] ?? null) && $element['label'] !== ''
                    ? $element['label']
                    : self::fixtureLabel(is_string($element['type'] ?? null) ? $element['type'] : '', $type),
                'width_mm' => is_int($element['width_mm'] ?? null) ? $element['width_mm'] : null,
                'height_mm' => is_int($element['height_mm'] ?? null) ? $element['height_mm'] : null,
                'offset_mm' => is_int($element['offset_mm'] ?? null) ? $element['offset_mm'] : null,
                'sill_height_mm' => is_int($element['sill_height_mm'] ?? null) ? $element['sill_height_mm'] : null,
                'is_blocking' => $type->blocksByDefault(),
                'must_stay_visible' => $type->mustStayVisibleByDefault(),
                'notes' => 'Fotoğraftan tespit edildi.',
            ]);

            $added++;
        }

        return $added;
    }

    /**
     * What to call a fixture on the customer's list.
     *
     * The constraint type is a category, not a name: a wall sconce and a pendant are both
     * "Diğer", and a list of three rows all saying "Diğer" tells the customer nothing about
     * their own room. The reading's own word is turned into the Turkish for it, and the
     * category is only the fallback.
     */
    private static function fixtureLabel(string $said, ConstraintType $type): string
    {
        $word = mb_strtolower($said);

        return match (true) {
            (bool) preg_match('/sconce|aplik/u', $word) => 'Aplik',
            (bool) preg_match('/chandelier|avize/u', $word) => 'Avize',
            (bool) preg_match('/pendant|sarkıt|sarkit/u', $word) => 'Sarkıt',
            (bool) preg_match('/ceiling.?light|spot|tavan/u', $word) => 'Tavan aydınlatması',
            (bool) preg_match('/wardrobe|closet|dolap/u', $word) => 'Gömme dolap',
            (bool) preg_match('/kitchen|mutfak|cabinetry/u', $word) => 'Mutfak dolabı',
            default => $type->label(),
        };
    }

    /**
     * The constraint an element the reading named corresponds to, or null to ignore it.
     *
     * Trim is ignored on purpose: a skirting board and a cornice are drawn as part of the
     * room, and putting them on a list of things furniture must avoid would fill that list
     * with two entries that apply to every wall and mean nothing.
     */
    private static function fixtureType(string $said): ?ConstraintType
    {
        $word = mb_strtolower($said);

        return match (true) {
            $word === '' => null,
            (bool) preg_match('/baseboard|skirting|süpürgelik|supurgelik|crown|cornice|kartonpiyer|molding|moulding/u', $word) => null,
            (bool) preg_match('/radiator|radyatör|radyator|petek|kalorifer|heater/u', $word) => ConstraintType::Radiator,
            (bool) preg_match('/column|kolon|pillar|pier/u', $word) => ConstraintType::Column,
            (bool) preg_match('/beam|kiriş|kiris/u', $word) => ConstraintType::Beam,
            (bool) preg_match('/fireplace|şömine|somine|hearth/u', $word) => ConstraintType::Fireplace,
            (bool) preg_match('/stair|merdiven/u', $word) => ConstraintType::Stairs,
            (bool) preg_match('/socket|priz|outlet/u', $word) => ConstraintType::Socket,
            (bool) preg_match('/switch|anahtar|düğme/u', $word) => ConstraintType::Switch_,
            (bool) preg_match('/wardrobe|closet|dolap|built.?in|gömme|kitchen|mutfak|cabinetry/u', $word) => ConstraintType::FixedFurniture,
            // A sconce, a pendant, a ceiling rose: not an obstacle a sofa cares about, but a
            // tall piece planned across one is a light nobody can use again.
            (bool) preg_match('/sconce|aplik|lamp|light|aydınlatma|aydinlatma|chandelier|avize|pendant/u', $word) => ConstraintType::Other,
            default => null,
        };
    }

    /**
     * The kind of opening this is: the reading's answer, or the width's.
     *
     * A kind the reading gave is trusted only if it is a kind that type can be — "sliding"
     * on a window is a misread, not a sliding window, and a room drawn from it would be
     * wrong in a way the customer cannot explain to anybody.
     *
     * @param  array<string, mixed>  $opening
     */
    private static function variantOf(ConstraintType $type, array $opening): ?OpeningVariant
    {
        $said = is_string($opening['variant'] ?? null)
            ? OpeningVariant::tryFrom($opening['variant'])
            : null;

        if ($said !== null && $said->fits($type)) {
            return $said;
        }

        return OpeningVariant::guess($type, $opening['width_mm'] ?? null, $opening['sill_height_mm'] ?? null);
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

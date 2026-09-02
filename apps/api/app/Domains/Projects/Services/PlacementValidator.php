<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomConstraint;

/**
 * Checks a proposed layout against the room it is for.
 *
 * The model is good at style and bad at arithmetic. It will cheerfully put a 2600mm sofa
 * against a 2200mm wall, and the render that follows will *look* fine — the image is not
 * to scale — while the shopping list beside it contains a sofa that does not fit through
 * the customer's living room. That is the failure this class exists to prevent, and it is
 * one nobody notices until a delivery van arrives.
 *
 * A rejected placement is recorded rather than silently dropped. A plan that quietly loses
 * a piece of furniture produces an image with a sideboard in it and a shopping list
 * without one, and the customer is left to work out which is wrong.
 *
 * Deliberately arithmetic, not judgement: it refuses what does not fit and says nothing
 * about taste. Whether a sideboard belongs on that wall is the model's business.
 */
final class PlacementValidator
{
    /**
     * Clearance to leave in front of a window or a door, in millimetres.
     *
     * Not a design opinion. A radiator boxed in stops heating the room and a window with a
     * wardrobe against it stops being a window, and the analysis marks both as things to
     * preserve for exactly that reason.
     */
    private const BLOCKING_CLEARANCE_MM = 300;

    /**
     * The categories whose width is genuinely limited by the wall they are placed against.
     *
     * This list exists because the check was previously applied to everything, and it threw
     * out three of one customer's eight choices on arithmetic that did not apply to any of
     * them: a rug lies on the floor, a curtain's fabric is two to two and a half times the
     * window because it gathers, and the sofa in question was placed — by the planner's own
     * words — "in the middle of the room, at least 40 cm clear of the wall".
     *
     * What followed was worse than three missing items. The renderer was handed a living
     * room with a television, a coffee table and nowhere to sit, and a photorealistic model
     * completes a scene like that whatever it has been told not to: it drew a corner sofa
     * nobody could buy and moved the walls to fit it in.
     *
     * So the test now applies only where it means something. Anything not named here is
     * still checked for a category and a price, and simply is not measured against a wall
     * it does not touch.
     */
    private const WALL_MOUNTED_CATEGORIES = [
        'tv-unitesi',
        'konsol',
        'kitaplik',
        'dolap',
        'gardirop',
        'vitrin',
        'komodin',
        'sifonyer',
        'yemek-masasi',
        'calisma-masasi',
        'yatak',
        'ayakkabilik',
        'portmanto',
    ];

    /**
     * Splits a planner's placements into what the room can take and what it cannot.
     *
     * @param  array<int, mixed>  $placements
     * @return array{accepted: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
     */
    public function check(Room $room, array $placements): array
    {
        $room->loadMissing('constraints');

        $accepted = [];
        $rejected = [];

        foreach ($placements as $placement) {
            if (! is_array($placement)) {
                continue;
            }

            $reason = $this->reasonToReject($room, $placement);

            if ($reason === null) {
                $accepted[] = $placement;

                continue;
            }

            $rejected[] = [...$placement, 'reason' => $reason];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $placement
     */
    private function reasonToReject(Room $room, array $placement): ?string
    {
        /*
         * A placement nobody can shop for.
         *
         * The category is the only part of a placement the product search can use, and for
         * a while nothing insisted on it: the response schema asked for an array and got
         * one, full of prose — "L köşe koltuk, TV ünitesinin karşısına, ön ayakları halının
         * üzerinde". A fine sentence and not a category, so the search returned nothing,
         * the shopping list came back empty, and with no products there were no product
         * photographs to send the renderer, which then drew furniture out of its own head.
         * Every stage reported success.
         *
         * Rejected rather than dropped, so it lands in the plan's `rejected` column where
         * somebody can see the model wandered off, instead of vanishing.
         */
        $category = $this->stringOrNull($placement['category'] ?? null);

        if ($category === null) {
            return 'Kategorisi belirtilmediği için bu öğe için ürün aranamaz.';
        }

        /*
         * Only things that stand against a wall are measured against one.
         *
         * The plan names a wall for almost everything, because a rug "belongs to" the
         * seating group and a curtain "belongs to" the window wall — but naming a wall is
         * not the same as leaning on it, and treating the two as one threw out a customer's
         * sofa, rug and curtains in a single pass.
         */
        if (! in_array($category, self::WALL_MOUNTED_CATEGORIES, true)) {
            return null;
        }

        $width = $this->millimetres($placement['max_width_mm'] ?? null);

        if ($width === null) {
            /*
             * No width given is accepted rather than refused. Not everything in a plan has
             * one — a rug, a wall colour — and demanding a measurement for them would
             * reject most of a perfectly good layout.
             */
            return null;
        }

        $wall = $this->wallOf($placement);

        $available = $this->wallLength($room, $wall);

        if ($available !== null && $width > $available) {
            return sprintf(
                'İstenen genişlik (%d mm) bu duvara sığmıyor (%d mm).',
                $width,
                $available,
            );
        }

        if ($wall === null) {
            return null;
        }

        $blocked = $this->blockedSpan($room, $wall);

        // A wall with a 1400mm window in the middle does not offer its full length to a
        // single piece of furniture, and the analysis said the window must stay visible.
        if ($blocked > 0 && $available !== null && $width > ($available - $blocked)) {
            return sprintf(
                'Bu duvarda korunması gereken öğeler var; %d mm yerine en fazla %d mm sığar.',
                $width,
                max(0, $available - $blocked),
            );
        }

        return null;
    }

    /**
     * How long the named wall is, if the room has been measured.
     *
     * Null when it has not. A room whose dimensions are a guess cannot refuse anything on
     * arithmetic, and pretending otherwise would reject real furniture on the strength of
     * a number nobody measured.
     */
    private function wallLength(Room $room, ?string $wall): ?int
    {
        if ($room->width_mm === null || $room->length_mm === null) {
            return null;
        }

        return match ($wall) {
            'north', 'south' => $room->width_mm,
            'east', 'west' => $room->length_mm,
            // No wall named: the longest one is the fairest assumption, because a
            // free-standing piece has the whole room to sit in.
            default => max($room->width_mm, $room->length_mm),
        };
    }

    /**
     * How much of a wall is taken up by things that must stay clear.
     *
     * Windows and doors, plus a margin on each side. A radiator is included because
     * boxing one in stops it heating the room, which is a mistake the customer discovers
     * in November.
     */
    private function blockedSpan(Room $room, string $wall): int
    {
        $blocked = 0;

        foreach ($room->constraints as $constraint) {
            if (! $constraint instanceof RoomConstraint) {
                continue;
            }

            if ($constraint->wall !== $wall) {
                continue;
            }

            if (! $constraint->is_blocking && ! $constraint->must_stay_visible) {
                continue;
            }

            $blocked += ($constraint->width_mm ?? 0) + (2 * self::BLOCKING_CLEARANCE_MM);
        }

        return $blocked;
    }

    /**
     * @param  array<string, mixed>  $placement
     */
    private function wallOf(array $placement): ?string
    {
        $wall = $placement['wall'] ?? null;

        return is_string($wall) && $wall !== '' ? $wall : null;
    }

    /**
     * A measurement, if it is one.
     *
     * Models answer "2200", 2200 and 2200.0 for the same thing, and a plan is not worth
     * rejecting over which. A negative or absurd figure *is* worth rejecting, and comes
     * back as null so the placement is treated as unmeasured rather than as fitting.
     */
    /** A non-empty string, or null for anything else a model might have put there. */
    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function millimetres(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value < 100_000 ? $value : null;
        }

        if (is_float($value)) {
            return $value > 0 && $value < 100_000 ? (int) round($value) : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;

            return $parsed > 0 && $parsed < 100_000 ? $parsed : null;
        }

        return null;
    }
}

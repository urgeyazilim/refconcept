<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;

/**
 * Whether a piece of furniture is somewhere the room will actually take it.
 *
 * The browser does this too, continuously, because a collision warning that arrives after a
 * round trip is a collision warning nobody waits for. This class exists anyway, and the
 * duplication is deliberate: the browser's answer is a courtesy to whoever is dragging, and
 * this one is the truth. A layout arrives over HTTP and nothing about it can be trusted —
 * not the coordinates, not the collision flags the client helpfully computed, not that the
 * sofa is inside the room at all.
 *
 * Everything is millimetres and integers. The arithmetic is rectangles on a floor plan: a
 * room is a box, a piece of furniture is a box, an opening is a span along one wall. That is
 * crude next to real geometry and it is the right crudeness — furniture is rectangular, it
 * sits at right angles almost always, and a system that modelled the curve of a chaise would
 * be precise about the one thing nobody is going to complain about.
 */
final class LayoutGeometry
{
    /**
     * Clearance a door needs in front of it, in millimetres.
     *
     * Not a design opinion: a door that opens into a wardrobe stops being a door. The same
     * figure the placement validator uses for the same reason.
     */
    public const DOOR_CLEARANCE_MM = 900;

    /**
     * Clearance in front of a window.
     *
     * Smaller than a door's, because things may stand in front of a window — a sofa with its
     * back to one is ordinary — but a wardrobe against it is not, and the analysis marks
     * windows as elements to preserve for exactly this reason.
     */
    public const WINDOW_CLEARANCE_MM = 300;

    /**
     * How much two pieces may overlap before it is a collision rather than a rug.
     *
     * A coffee table standing on a carpet overlaps it completely and is correct; a sofa
     * standing in a sideboard overlaps it completely and is not. The difference is category,
     * handled by {@see overlapAllowed()}, and this tolerance is only for the millimetre or
     * two of touching that snapping produces on purpose.
     */
    private const TOUCH_TOLERANCE_MM = 20;

    /**
     * The categories that other things are meant to stand on top of.
     *
     * A rug is the whole reason this list exists. Without it every layout with a carpet in
     * it reports four collisions and the customer learns to ignore the warnings, which is
     * worse than having none.
     */
    private const UNDERFOOT_CATEGORIES = ['hali', 'kilim', 'paspas'];

    /**
     * Checks every item in a layout and returns the state each one is in.
     *
     * Returned rather than saved, so a caller can use this to answer "what would happen if I
     * put it here" without writing anything. Persisting is the caller's decision.
     *
     * @return array<string, string> item id => ok | warning | blocked
     */
    public function evaluate(DesignLayout $layout): array
    {
        $layout->loadMissing(['items.sku.dimensions', 'items.product.categories', 'geometry', 'room.constraints']);

        $geometry = $layout->geometry;
        $items = $layout->items->all();

        /** @var array<string, string> $states */
        $states = [];

        foreach ($items as $item) {
            $states[(string) $item->getKey()] = $this->stateOf($item, $items, $geometry, $layout->room?->constraints?->all() ?? []);
        }

        return $states;
    }

    /**
     * Where a piece stands, as a rectangle on the floor plan.
     *
     * The position is the item's centre rather than a corner, because rotation is about the
     * centre and a corner-anchored box jumps across the room when somebody turns it.
     *
     * @return array{x1: int, z1: int, x2: int, z2: int}
     */
    public function rectangleOf(DesignLayoutItem $item): array
    {
        $footprint = $item->footprintMm();

        $halfWidth = intdiv($footprint['width'], 2);
        $halfDepth = intdiv($footprint['depth'], 2);

        return [
            'x1' => $item->position_x_mm - $halfWidth,
            'z1' => $item->position_z_mm - $halfDepth,
            'x2' => $item->position_x_mm + $halfWidth,
            'z2' => $item->position_z_mm + $halfDepth,
        ];
    }

    // --- internals -----------------------------------------------------------

    /**
     * @param  list<DesignLayoutItem>  $others
     * @param  list<RoomConstraint>  $constraints
     */
    private function stateOf(
        DesignLayoutItem $item,
        array $others,
        ?RoomGeometryVersion $geometry,
        array $constraints,
    ): string {
        $rectangle = $this->rectangleOf($item);

        // Outside the room is not a warning. Nothing can be delivered to a position that is
        // through a wall, and a client that produced one is a client with a bug.
        if ($geometry !== null && ! $this->insideRoom($rectangle, $geometry)) {
            return 'blocked';
        }

        foreach ($others as $other) {
            if ($other->getKey() === $item->getKey()) {
                continue;
            }

            if ($this->overlapAllowed($item, $other)) {
                continue;
            }

            if ($this->overlaps($rectangle, $this->rectangleOf($other))) {
                return 'blocked';
            }
        }

        // Doorways and windows are the difference between a layout that looks fine on a plan
        // and a room somebody cannot walk into.
        foreach ($constraints as $constraint) {
            if ($geometry === null) {
                continue;
            }

            $span = $this->clearanceRectangle($constraint, $geometry);

            if ($span === null) {
                continue;
            }

            if ($this->overlaps($rectangle, $span)) {
                /*
                 * A blocked doorway is a refusal; a covered window is a warning.
                 *
                 * The difference is whether the room still works. A sofa with its back to a
                 * window is an ordinary arrangement somebody may want and be told about; a
                 * wardrobe across the only door is not a taste question.
                 */
                return $this->swings($constraint) ? 'blocked' : 'warning';
            }
        }

        return 'ok';
    }

    /** @param  array{x1: int, z1: int, x2: int, z2: int}  $rectangle */
    private function insideRoom(array $rectangle, RoomGeometryVersion $geometry): bool
    {
        return $rectangle['x1'] >= -self::TOUCH_TOLERANCE_MM
            && $rectangle['z1'] >= -self::TOUCH_TOLERANCE_MM
            && $rectangle['x2'] <= $geometry->width_mm + self::TOUCH_TOLERANCE_MM
            && $rectangle['z2'] <= $geometry->length_mm + self::TOUCH_TOLERANCE_MM;
    }

    /**
     * @param  array{x1: int, z1: int, x2: int, z2: int}  $a
     * @param  array{x1: int, z1: int, x2: int, z2: int}  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        $tolerance = self::TOUCH_TOLERANCE_MM;

        return $a['x1'] < $b['x2'] - $tolerance
            && $a['x2'] > $b['x1'] + $tolerance
            && $a['z1'] < $b['z2'] - $tolerance
            && $a['z2'] > $b['z1'] + $tolerance;
    }

    /**
     * Whether these two are allowed to occupy the same floor.
     *
     * Anything may stand on a rug, and a wall-hung piece shares no floor with anything
     * because its footprint is on the wall rather than under it.
     */
    private function overlapAllowed(DesignLayoutItem $item, DesignLayoutItem $other): bool
    {
        // One of them is off the floor entirely.
        if ($item->position_y_mm > 0 || $other->position_y_mm > 0) {
            return true;
        }

        return $this->isUnderfoot($item) || $this->isUnderfoot($other);
    }

    private function isUnderfoot(DesignLayoutItem $item): bool
    {
        // A product belongs to several categories, so this asks whether *any* of them is
        // something people stand furniture on rather than picking a primary one — a rug
        // filed under both "halı" and "ev tekstili" is still a rug.
        $slugs = $item->product?->categories?->pluck('slug')->all() ?? [];

        return array_intersect($slugs, self::UNDERFOOT_CATEGORIES) !== [];
    }

    /**
     * Whether this opening needs room to swing into.
     *
     * A balcony door is a door. It was not, for one afternoon, and the layout engine let a
     * sideboard stand across the only way onto the balcony.
     */
    private function swings(RoomConstraint $constraint): bool
    {
        return in_array($constraint->type, [ConstraintType::Door, ConstraintType::BalconyDoor], true);
    }

    /**
     * The floor a door or window needs kept clear, as a rectangle.
     *
     * Returns null for anything without a wall and an offset — a constraint recorded as "a
     * radiator, somewhere" cannot be turned into a position, and guessing one would block a
     * corner of the room for no stated reason.
     *
     * @return array{x1: int, z1: int, x2: int, z2: int}|null
     */
    private function clearanceRectangle(RoomConstraint $constraint, RoomGeometryVersion $geometry): ?array
    {
        $wall = $constraint->wall;
        $offset = $constraint->offset_mm;
        $width = $constraint->width_mm;

        if ($wall === null || $offset === null || $width === null) {
            return null;
        }

        $depth = $this->swings($constraint) ? self::DOOR_CLEARANCE_MM : self::WINDOW_CLEARANCE_MM;

        /*
         * Walls are named from inside the room looking at them. North and south run along
         * the width and are offset from the west corner; east and west run along the length
         * and are offset from the north corner. Any other value is a wall this room does not
         * have, and inventing a position for it would put a no-go zone somewhere arbitrary.
         */
        return match ($wall) {
            'north' => ['x1' => $offset, 'z1' => 0, 'x2' => $offset + $width, 'z2' => $depth],
            'south' => ['x1' => $offset, 'z1' => $geometry->length_mm - $depth, 'x2' => $offset + $width, 'z2' => $geometry->length_mm],
            'west' => ['x1' => 0, 'z1' => $offset, 'x2' => $depth, 'z2' => $offset + $width],
            'east' => ['x1' => $geometry->width_mm - $depth, 'z1' => $offset, 'x2' => $geometry->width_mm, 'z2' => $offset + $width],
            default => null,
        };
    }
}

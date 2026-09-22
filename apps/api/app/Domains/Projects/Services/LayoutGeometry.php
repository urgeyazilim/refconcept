<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Enums\DoorSwing;
use App\Domains\Projects\Enums\OpeningVariant;
use App\Domains\Projects\Models\DesignLayout;
use App\Domains\Projects\Models\DesignLayoutItem;
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
     * The categories that hang on a wall rather than stand on the floor.
     *
     * A picture above a sofa and a curtain behind one are the ordinary arrangement, not an
     * overlap, and a picture above a doorway blocks nobody's way through it. Same list as
     * the browser's.
     */
    private const WALL_MOUNTED_CATEGORIES = ['tablo', 'ayna', 'duvar-aydinlatma', 'perde'];

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
        $layout->loadMissing(['items.sku.dimensions', 'items.product.categories', 'items.product.primaryCategory', 'geometry', 'room.constraints']);

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
        // The exact outline, turned as the piece is turned. Two pieces at an angle touch when
        // their outlines do — not when the boxes round them do. Same rule as the browser.
        $shape = $this->polygonOf($item);

        // Outside the room is not a warning. Nothing can be delivered to a position that is
        // through a wall, and a client that produced one is a client with a bug.
        if ($geometry !== null && ! $this->polygonInsideRoom($shape, $geometry)) {
            return 'blocked';
        }

        foreach ($others as $other) {
            if ($other->getKey() === $item->getKey()) {
                continue;
            }

            if ($this->overlapAllowed($item, $other)) {
                continue;
            }

            if ($this->polygonsOverlap($shape, $this->polygonOf($other))) {
                return 'blocked';
            }
        }

        // A picture above a doorway blocks nobody's way through it.
        if ($this->isWallMounted($item)) {
            return 'ok';
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

            if ($this->polygonsOverlap($shape, $this->polygonFromRect($span))) {
                /*
                 * Told, not refused.
                 *
                 * A doorway used to be 'blocked', and 'blocked' is what the arranger reads as
                 * "put it somewhere else". So an armchair could not be placed in front of the
                 * balcony glass — in a room where the glass is what you look at from the
                 * armchair — and nothing on screen said why; it simply slid away each time.
                 *
                 * The clearance in front of a door is what a designer leaves, not something
                 * the world enforces, and somebody who puts a chair there has a reason. The
                 * things that are not taste questions — a piece inside a wall, two pieces in
                 * one place — are still refused, and they are refused because they are
                 * impossible rather than because they are unwise.
                 *
                 * Nothing at all for an opening that takes no floor in this room. A warning
                 * about a problem that does not exist is one nobody reads the next time.
                 */
                if ($this->sweepsFloor($constraint)) {
                    return 'warning';
                }

                return $constraint->type === ConstraintType::Window ? 'warning' : 'ok';
            }
        }

        return 'ok';
    }

    /**
     * Where a piece stands, exactly: its rectangle turned about its centre.
     *
     * The same turn the browser draws — clockwise seen from above, +x towards +z — so the
     * outline is the mesh's and not its mirror image. Rounded to whole millimetres, like
     * everything else here.
     *
     * @return list<array{x: int, z: int}>
     */
    public function polygonOf(DesignLayoutItem $item): array
    {
        $dimensions = $item->sku?->dimensions;

        $halfWidth = ((int) ($dimensions->width_mm ?? 0)) / 2;
        $halfDepth = ((int) ($dimensions->depth_mm ?? 0)) / 2;

        $radians = deg2rad(((int) $item->rotation_y_deg % 360 + 360) % 360);
        $cos = cos($radians);
        $sin = sin($radians);

        $corner = fn (float $dx, float $dz): array => [
            'x' => (int) round($item->position_x_mm + $dx * $cos - $dz * $sin),
            'z' => (int) round($item->position_z_mm + $dx * $sin + $dz * $cos),
        ];

        return [
            $corner(-$halfWidth, -$halfDepth),
            $corner($halfWidth, -$halfDepth),
            $corner($halfWidth, $halfDepth),
            $corner(-$halfWidth, $halfDepth),
        ];
    }

    /**
     * @param  array{x1: int, z1: int, x2: int, z2: int}  $rect
     * @return list<array{x: int, z: int}>
     */
    private function polygonFromRect(array $rect): array
    {
        return [
            ['x' => $rect['x1'], 'z' => $rect['z1']],
            ['x' => $rect['x2'], 'z' => $rect['z1']],
            ['x' => $rect['x2'], 'z' => $rect['z2']],
            ['x' => $rect['x1'], 'z' => $rect['z2']],
        ];
    }

    /** @param  list<array{x: int, z: int}>  $polygon */
    private function polygonInsideRoom(array $polygon, RoomGeometryVersion $geometry): bool
    {
        foreach ($polygon as $point) {
            if ($point['x'] < -self::TOUCH_TOLERANCE_MM
                || $point['z'] < -self::TOUCH_TOLERANCE_MM
                || $point['x'] > $geometry->width_mm + self::TOUCH_TOLERANCE_MM
                || $point['z'] > $geometry->length_mm + self::TOUCH_TOLERANCE_MM) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether two outlines share floor, allowing the tolerance that snapping produces.
     *
     * Separating-axis theorem, for the one shape it is trivial on: two rectangles are apart
     * if and only if one of their four edge normals separates their projections.
     *
     * @param  list<array{x: int, z: int}>  $a
     * @param  list<array{x: int, z: int}>  $b
     */
    private function polygonsOverlap(array $a, array $b): bool
    {
        foreach ([$a, $b] as $polygon) {
            foreach ([0, 1] as $index) {
                $from = $polygon[$index];
                $to = $polygon[$index + 1];
                $length = hypot($to['x'] - $from['x'], $to['z'] - $from['z']);

                if ($length <= 0) {
                    continue;
                }

                $axis = ['x' => -($to['z'] - $from['z']) / $length, 'z' => ($to['x'] - $from['x']) / $length];

                [$minA, $maxA] = $this->projection($a, $axis);
                [$minB, $maxB] = $this->projection($b, $axis);

                if ($maxA <= $minB + self::TOUCH_TOLERANCE_MM || $maxB <= $minA + self::TOUCH_TOLERANCE_MM) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  list<array{x: int, z: int}>  $polygon
     * @param  array{x: float, z: float}  $axis
     * @return array{0: float, 1: float}
     */
    private function projection(array $polygon, array $axis): array
    {
        $min = PHP_FLOAT_MAX;
        $max = -PHP_FLOAT_MAX;

        foreach ($polygon as $point) {
            $along = $point['x'] * $axis['x'] + $point['z'] * $axis['z'];

            $min = min($min, $along);
            $max = max($max, $along);
        }

        return [$min, $max];
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

        return $this->isUnderfoot($item) || $this->isUnderfoot($other)
            || $this->isWallMounted($item) || $this->isWallMounted($other);
    }

    private function isUnderfoot(DesignLayoutItem $item): bool
    {
        return array_intersect($this->categorySlugs($item), self::UNDERFOOT_CATEGORIES) !== [];
    }

    private function isWallMounted(DesignLayoutItem $item): bool
    {
        return array_intersect($this->categorySlugs($item), self::WALL_MOUNTED_CATEGORIES) !== [];
    }

    /**
     * @return list<string>
     */
    private function categorySlugs(DesignLayoutItem $item): array
    {
        $product = $item->product;

        if ($product === null) {
            return [];
        }

        /*
         * The primary category and the secondary ones together.
         *
         * Both, because they are populated by different paths: importers and the catalogue
         * screens fill the many-to-many, while everything that creates a product in one go
         * sets only `primary_category_id`. Reading either alone makes this answer depend on
         * how the rug happened to get into the catalogue — and a rug that reports itself as
         * ordinary furniture puts a collision warning under every coffee table, which is how
         * somebody learns to ignore the warnings.
         */
        $slugs = $product->categories->pluck('slug')->all();

        $primary = $product->primaryCategory?->slug;

        if ($primary !== null) {
            $slugs[] = $primary;
        }

        return array_values(array_map('strval', $slugs));
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
     * Whether this opening actually takes floor inside the room.
     *
     * {@see swings()} asks what kind of thing it is; this asks what it does, and they are not
     * the same question. A door onto the balcony sweeps the balcony. A sliding one sweeps
     * nothing at all — that is the entire reason somebody fits one. A top-hung window takes
     * the air above whatever is under it and nothing off the floor.
     *
     * It was the kind alone, so the south balcony door reserved nine hundred millimetres of
     * the room along two metres of wall whichever way it opened.
     *
     * The browser has the same rule in `footprint.ts`. Both, or the room the customer drags
     * in and the room the server arranges disagree about where a chair may stand.
     */
    private function sweepsFloor(RoomConstraint $constraint): bool
    {
        if (! $this->swings($constraint)) {
            return false;
        }

        $variant = $constraint->variant ?? OpeningVariant::guess($constraint->type, $constraint->width_mm, $constraint->sill_height_mm);

        if ($variant === OpeningVariant::Sliding || $variant === OpeningVariant::Folding) {
            return false;
        }

        // Nobody said which way: a door is assumed to open inward, which is the careful
        // assumption — it is the one that produces a warning rather than silence.
        return ($constraint->swing ?? DoorSwing::StartIn)->opensIn();
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

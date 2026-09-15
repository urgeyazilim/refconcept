<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Enums\ConstraintType;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;

/**
 * What is already in the room, while it is being arranged.
 *
 * Split out from {@see LayoutComposer} because the composer is a set of rules about where
 * furniture goes and this is the bookkeeping those rules need — which wall has space left,
 * what is standing where, which stretch of floor a door is owed. Keeping them apart is what
 * lets the rules read like rules.
 *
 * Everything is millimetres from the room's origin corner. "Along" always means the wall's
 * own axis, measured from the origin, the same way the geometry stores an opening's offset:
 * north and south from x = 0, east and west from z = 0.
 */
final class LayoutComposerState
{
    /** Kept clear at the end of every wall, so nothing is wedged into a corner. */
    private const CORNER_MM = 150;

    /** A hand's width either side of a door, so it can open and somebody can get through. */
    private const DOOR_MARGIN_MM = 300;

    /**
     * How far into the room a doorway is owed (K13, dolaşım): a person walking in, plus the
     * door's own swing. Nothing on the floor stands inside it, whichever wall it came from.
     */
    public const DOOR_CLEAR_MM = 900;

    /** Things that lie on the floor under other things, and so never occupy it. */
    private const UNDERFOOT = ['hali', 'kilim', 'paspas'];

    /** @var list<array<string, mixed>> */
    private array $placed = [];

    /** @var array<string, list<array{from: int, to: int}>> */
    private array $taken = ['north' => [], 'south' => [], 'east' => [], 'west' => []];

    /**
     * The floor in front of every door, as rectangles: x from/to, z from/to.
     *
     * @var list<array{x0: int, x1: int, z0: int, z1: int}>
     */
    private array $doorZones = [];

    /** Why the last placement was refused, when it was — read once by the composer. */
    private ?string $refusal = null;

    /** @param  list<RoomConstraint>  $openings */
    public function __construct(
        private readonly RoomGeometryVersion $geometry,
        private readonly array $openings,
    ) {
        foreach ($this->openings as $opening) {
            $wall = $opening->wall;
            $offset = $opening->offset_mm;
            $width = $opening->width_mm;

            if ($wall === null || $offset === null || $width === null || ! isset($this->taken[$wall])) {
                continue;
            }

            /*
             * A door takes the wall it is in and a hand's width either side; a window takes
             * only itself.
             *
             * The difference is what may stand there. Nothing may stand in a doorway, and a
             * sideboard beside one with no room to open the door is the same problem one step
             * along. A sofa under a window is ordinary, so the window blocks only the glass.
             */
            $isDoor = in_array($opening->type, [ConstraintType::Door, ConstraintType::BalconyDoor], true);
            $margin = $isDoor ? self::DOOR_MARGIN_MM : 0;

            $this->taken[$wall][] = ['from' => $offset - $margin, 'to' => $offset + $width + $margin];

            if ($isDoor) {
                $this->reserveDoorway($wall, $offset - $margin, $offset + $width + $margin);
            }
        }
    }

    /**
     * Keeps the floor in front of a door empty, on the door's wall and round the corner.
     *
     * A door in a corner is owed the first 900 mm of the wall next to it as well: a wardrobe
     * that starts at that corner stands in the doorway just as surely as one on the door's own
     * wall. Floating pieces — a table, a floated sofa, a lamp — are checked against the same
     * rectangle by {@see clearOfDoors()}.
     */
    private function reserveDoorway(string $wall, int $from, int $to): void
    {
        $depth = self::DOOR_CLEAR_MM;

        $this->doorZones[] = match ($wall) {
            'north' => ['x0' => $from, 'x1' => $to, 'z0' => 0, 'z1' => $depth],
            'south' => ['x0' => $from, 'x1' => $to, 'z0' => $this->length() - $depth, 'z1' => $this->length()],
            'west' => ['x0' => 0, 'x1' => $depth, 'z0' => $from, 'z1' => $to],
            default => ['x0' => $this->width() - $depth, 'x1' => $this->width(), 'z0' => $from, 'z1' => $to],
        };

        $extent = in_array($wall, ['north', 'south'], true) ? $this->width() : $this->length();

        // The walls at either end of this one, in the order their axis runs from this wall.
        [$atStart, $atEnd] = match ($wall) {
            'north', 'south' => ['west', 'east'],
            default => ['north', 'south'],
        };

        $startOfAdjacent = $wall === 'south' || $wall === 'east' ? $extent : 0;

        if ($from <= self::CORNER_MM) {
            $this->taken[$atStart][] = $this->cornerSpan($startOfAdjacent, $wall);
        }

        if ($to >= $extent - self::CORNER_MM) {
            $this->taken[$atEnd][] = $this->cornerSpan($startOfAdjacent, $wall);
        }
    }

    /**
     * The stretch of an adjacent wall a corner door takes, measured along that wall's axis.
     *
     * @return array{from: int, to: int}
     */
    private function cornerSpan(int $cornerAlongAdjacent, string $doorWall): array
    {
        $depth = self::DOOR_CLEAR_MM;

        // The adjacent wall's axis starts at the north or west corner; a door on the south
        // or east wall sits at the far end of it.
        return $doorWall === 'north' || $doorWall === 'west'
            ? ['from' => 0, 'to' => $depth]
            : ['from' => $cornerAlongAdjacent - $depth, 'to' => $cornerAlongAdjacent];
    }

    /**
     * Whether a floor piece stays out of every doorway.
     *
     * @param  array<string, mixed>  $item
     */
    public function clearOfDoors(array $item): bool
    {
        if ((int) ($item['position_y_mm'] ?? 0) > 0 || in_array((string) ($item['category'] ?? ''), self::UNDERFOOT, true)) {
            return true;
        }

        [$x0, $x1, $z0, $z1] = $this->boxOf($item);

        foreach ($this->doorZones as $zone) {
            if ($x0 < $zone['x1'] && $x1 > $zone['x0'] && $z0 < $zone['z1'] && $z1 > $zone['z0']) {
                return false;
            }
        }

        return true;
    }

    /** Records why a piece could not go where a rule wanted it. */
    public function refuse(string $reason): void
    {
        $this->refusal = $reason;
    }

    /** The last refusal, cleared on reading. */
    public function takeRefusal(): ?string
    {
        $reason = $this->refusal;
        $this->refusal = null;

        return $reason;
    }

    /** The wall across the room from a given one. */
    public static function opposite(string $wall): string
    {
        return match ($wall) {
            'north' => 'south',
            'south' => 'north',
            'east' => 'west',
            default => 'east',
        };
    }

    /**
     * The room's focal wall (K13, odak): the television if there is one, else the widest
     * window, else the wall with the most room. Seating faces it.
     */
    public function focalWall(): string
    {
        $television = $this->firstOf('tv-unitesi');

        if ($television !== null) {
            $wall = $this->wallOf($television);

            if ($wall !== null) {
                return $wall;
            }
        }

        return $this->windowWall() ?? $this->emptiestWall();
    }

    /** The wall with the widest window, if the room has one. */
    public function windowWall(): ?string
    {
        return $this->widestWindow()?->wall;
    }

    public function widestWindow(): ?RoomConstraint
    {
        $best = null;

        foreach ($this->openings as $opening) {
            if ($opening->type !== ConstraintType::Window || $opening->wall === null || $opening->width_mm === null) {
                continue;
            }

            if ($best === null || $opening->width_mm > $best->width_mm) {
                $best = $opening;
            }
        }

        return $best;
    }

    /**
     * The first placed piece of a category.
     *
     * @return array<string, mixed>|null
     */
    public function firstOf(string $category): ?array
    {
        foreach ($this->placed as $item) {
            if ((string) ($item['category'] ?? '') === $category) {
                return $item;
            }
        }

        return null;
    }

    /** How many placed pieces are of a category. */
    public function countOf(string $category): int
    {
        return count(array_filter($this->placed, static fn (array $item): bool => (string) ($item['category'] ?? '') === $category));
    }

    /**
     * The floor the standing furniture covers, in square millimetres.
     *
     * Rugs and anything hung on a wall do not count: the rule this feeds (K13, ölçek) is about
     * what somebody has to walk around.
     */
    public function occupiedFloorMm2(): int
    {
        $total = 0;

        foreach ($this->placed as $item) {
            if ((int) ($item['position_y_mm'] ?? 0) > 0 || in_array((string) ($item['category'] ?? ''), self::UNDERFOOT, true)) {
                continue;
            }

            $total += (int) $item['width_mm'] * (int) $item['depth_mm'];
        }

        return $total;
    }

    public function floorMm2(): int
    {
        return $this->width() * $this->length();
    }

    /** The full length of a wall, along its own axis. */
    public function extentOf(string $wall): int
    {
        return in_array($wall, ['north', 'south'], true) ? $this->width() : $this->length();
    }

    /**
     * How far the furniture against a wall reaches into the room.
     *
     * What a piece floated off the opposite wall has to leave room for.
     */
    public function reachFrom(string $wall): int
    {
        $reach = 0;

        foreach ($this->placed as $item) {
            if ($this->wallOf($item) !== $wall || (int) ($item['position_y_mm'] ?? 0) > 0) {
                continue;
            }

            [$x0, $x1, $z0, $z1] = $this->boxOf($item);

            $reach = max($reach, match ($wall) {
                'north' => $z1,
                'south' => $this->length() - $z0,
                'west' => $x1,
                default => $this->width() - $x0,
            });
        }

        return $reach;
    }

    /**
     * The axis-aligned box a piece stands on: x from/to, z from/to.
     *
     * @param  array<string, mixed>  $item
     * @return array{int, int, int, int}
     */
    public function boxOf(array $item): array
    {
        $rotation = ((int) ($item['rotation_y_deg'] ?? 0) % 360 + 360) % 360;
        $turned = $rotation === 90 || $rotation === 270;

        $alongX = $turned ? (int) $item['depth_mm'] : (int) $item['width_mm'];
        $alongZ = $turned ? (int) $item['width_mm'] : (int) $item['depth_mm'];

        $x = (int) $item['position_x_mm'];
        $z = (int) $item['position_z_mm'];

        return [$x - intdiv($alongX, 2), $x + intdiv($alongX, 2), $z - intdiv($alongZ, 2), $z + intdiv($alongZ, 2)];
    }

    public function width(): int
    {
        return $this->geometry->width_mm;
    }

    public function length(): int
    {
        return $this->geometry->length_mm;
    }

    public function centreX(): int
    {
        return intdiv($this->width(), 2);
    }

    public function centreZ(): int
    {
        return intdiv($this->length(), 2);
    }

    /**
     * Where along a wall a piece of this width can stand, as its centre.
     *
     * Null when the wall has no run long enough. That is a real answer: a 2200 mm sofa does
     * not go on a 1900 mm stretch of wall, and the composer says so rather than overlapping
     * it with the wardrobe already there.
     *
     * `centred` picks the longest free run and puts the piece in the middle of it, which is
     * what seating and pictures want. Everything else takes the first run it fits in, so a
     * wall fills up from one end instead of leaving unusable gaps between centred pieces.
     */
    public function runAlong(string $wall, int $width, bool $centred): ?int
    {
        $extent = in_array($wall, ['north', 'south'], true) ? $this->width() : $this->length();

        $free = $this->freeRuns($wall, $extent);

        if ($free === []) {
            return null;
        }

        if ($centred) {
            usort($free, static fn (array $a, array $b): int => ($b['to'] - $b['from']) <=> ($a['to'] - $a['from']));

            $run = $free[0];

            return $width <= $run['to'] - $run['from']
                ? intdiv($run['from'] + $run['to'], 2)
                : null;
        }

        foreach ($free as $run) {
            if ($width <= $run['to'] - $run['from']) {
                return $run['from'] + intdiv($width, 2);
            }
        }

        return null;
    }

    /** Whether a piece this wide can stand centred at this point along a wall. */
    public function fitsAlong(string $wall, int $along, int $width): bool
    {
        $from = $along - intdiv($width, 2);
        $to = $along + intdiv($width, 2);

        foreach ($this->freeRuns($wall, $this->extentOf($wall)) as $run) {
            if ($from >= $run['from'] && $to <= $run['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records a piece, so nothing else is put on top of it.
     *
     * @param  array<string, mixed>  $item
     */
    public function occupy(array $item): void
    {
        $this->placed[] = $item;

        $wall = $this->wallOf($item);

        if ($wall === null) {
            return;
        }

        $along = $this->alongOf($wall, $item);
        $span = $this->spanAlong($wall, $item);

        $this->taken[$wall][] = ['from' => $along - intdiv($span, 2), 'to' => $along + intdiv($span, 2)];
    }

    /**
     * The most recently placed seat, which is what a coffee table arranges itself around.
     *
     * @return array<string, mixed>|null
     */
    public function lastSeating(): ?array
    {
        $seating = ['kanepe', 'koltuk', 'berjer', 'kose-takimi', 'sedir'];

        for ($index = count($this->placed) - 1; $index >= 0; $index--) {
            if (in_array((string) ($this->placed[$index]['category'] ?? ''), $seating, true)) {
                return $this->placed[$index];
            }
        }

        return null;
    }

    /**
     * The widest thing standing against a wall, which is what a picture hangs above.
     *
     * Widest rather than tallest: height is optional in the catalogue and width is not, and
     * on the wall a sideboard occupies, the widest piece is the one a picture is centred over
     * in practice.
     *
     * @return array<string, mixed>|null
     */
    public function tallestOn(string $wall): ?array
    {
        $best = null;

        foreach ($this->placed as $item) {
            if ($this->wallOf($item) !== $wall || (int) $item['position_y_mm'] > 0) {
                continue;
            }

            if ($best === null || (int) $item['width_mm'] > (int) $best['width_mm']) {
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * Where a piece sits along a given wall's axis.
     *
     * @param  array<string, mixed>  $item
     */
    public function alongOf(string $wall, array $item): int
    {
        return in_array($wall, ['north', 'south'], true)
            ? (int) $item['position_x_mm']
            : (int) $item['position_z_mm'];
    }

    /** The wall with the longest single free run. */
    public function emptiestWall(): string
    {
        return $this->wallsBySpace()[0];
    }

    /**
     * The walls, longest free run first.
     *
     * @return list<string>
     */
    public function wallsBySpace(): array
    {
        $walls = ['north', 'south', 'east', 'west'];

        usort($walls, fn (string $a, string $b): int => $this->longestRun($b) <=> $this->longestRun($a));

        return $walls;
    }

    // --- internals -------------------------------------------------------------

    private function longestRun(string $wall): int
    {
        $extent = in_array($wall, ['north', 'south'], true) ? $this->width() : $this->length();

        $longest = 0;

        foreach ($this->freeRuns($wall, $extent) as $run) {
            $longest = max($longest, $run['to'] - $run['from']);
        }

        return $longest;
    }

    /**
     * The stretches of a wall nothing is standing in.
     *
     * @return list<array{from: int, to: int}>
     */
    private function freeRuns(string $wall, int $extent): array
    {
        $blocked = $this->taken[$wall] ?? [];

        usort($blocked, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $runs = [];
        $cursor = self::CORNER_MM;
        $end = $extent - self::CORNER_MM;

        foreach ($blocked as $span) {
            if ($span['from'] > $cursor) {
                $runs[] = ['from' => $cursor, 'to' => min($span['from'], $end)];
            }

            $cursor = max($cursor, $span['to']);
        }

        if ($cursor < $end) {
            $runs[] = ['from' => $cursor, 'to' => $end];
        }

        return array_values(array_filter($runs, static fn (array $run): bool => $run['to'] > $run['from']));
    }

    /**
     * Which wall a piece is standing against, if any.
     *
     * Read back from where it ended up rather than remembered from where it was sent, because
     * a piece the composer floated off a wall is still that wall's piece, and one dropped in
     * the middle of the floor belongs to no wall at all.
     *
     * @param  array<string, mixed>  $item
     */
    public function wallOf(array $item): ?string
    {
        $x = (int) $item['position_x_mm'];
        $z = (int) $item['position_z_mm'];

        $distances = [
            'north' => $z,
            'south' => $this->length() - $z,
            'west' => $x,
            'east' => $this->width() - $x,
        ];

        asort($distances);

        $wall = array_key_first($distances);

        // A metre is generous enough to catch a sofa floated off a wall and tight enough to
        // leave a rug in the middle of the room belonging to nothing.
        return $distances[$wall] <= 1_000 ? (string) $wall : null;
    }

    /**
     * How much of a wall a piece takes up along that wall's axis.
     *
     * @param  array<string, mixed>  $item
     */
    private function spanAlong(string $wall, array $item): int
    {
        $width = (int) $item['width_mm'];
        $depth = (int) $item['depth_mm'];

        $rotation = ((int) $item['rotation_y_deg'] % 360 + 360) % 360;

        $turned = $rotation === 90 || $rotation === 270;

        // On the north and south walls the piece's width runs along the wall unless it has
        // been turned; on the east and west walls it is the other way round.
        $alongX = ! $turned;

        if (in_array($wall, ['north', 'south'], true)) {
            return $alongX ? $width : $depth;
        }

        return $alongX ? $depth : $width;
    }
}

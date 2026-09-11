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

    /** @var list<array<string, mixed>> */
    private array $placed = [];

    /** @var array<string, list<array{from: int, to: int}>> */
    private array $taken = ['north' => [], 'south' => [], 'east' => [], 'west' => []];

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
            $margin = in_array($opening->type, [ConstraintType::Door, ConstraintType::BalconyDoor], true) ? 300 : 0;

            $this->taken[$wall][] = ['from' => $offset - $margin, 'to' => $offset + $width + $margin];
        }
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
    private function wallOf(array $item): ?string
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

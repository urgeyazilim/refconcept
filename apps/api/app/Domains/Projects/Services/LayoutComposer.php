<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;

/**
 * Turns a design's decisions into positions in millimetres.
 *
 * The plan the model writes is in words: a sofa on the north wall, a rug under the seating
 * group, a picture above the sideboard. Words are the right output for it — asking a language
 * model for coordinates gets coordinates that look like coordinates and put a wardrobe
 * through a doorway, because nothing in it is checking. Arithmetic is our job, and it is the
 * part that has to be right: this is the difference between a picture of a room and a room
 * somebody can walk through.
 *
 * So the rules here are ordinary, boring and explicit. Wall pieces go against their wall,
 * in order, skipping the space doors and windows are owed. Seating faces the focal wall and
 * stands off it when the room can afford it, because furniture lined up against the walls of
 * a large room is a waiting area. Rugs go under the seating, tables in front of it, pictures
 * above whatever is below them.
 *
 * Nothing here invents furniture. It positions exactly the pieces it is given — the ones the
 * customer's own catalogue matched — and reports the ones it could not place rather than
 * finding somewhere for them. A layout that quietly puts a wardrobe in the middle of the
 * floor is worse than one that says it did not fit.
 */
final class LayoutComposer
{
    /** How far off a wall a piece stands when it is meant to be against it. */
    private const AGAINST_WALL_MM = 60;

    /** How far a coffee table sits from the seat in front of it. */
    private const TABLE_GAP_MM = 420;

    /** How far seating floats off the wall in a room large enough for it to. */
    private const FLOAT_MM = 350;

    /** Below this the room cannot afford a floating seating group and everything hugs a wall. */
    private const FLOAT_THRESHOLD_MM = 3_800;

    /** Height a picture hangs at, centre above the floor. */
    private const PICTURE_CENTRE_MM = 1_500;

    /**
     * The pieces that belong against a wall, in the order they get first choice of one.
     *
     * Ordered by how badly each needs its wall: a wardrobe has nowhere else to be, a
     * sideboard has a second-best wall, a bookcase can go almost anywhere along one.
     */
    private const WALL_PIECES = [
        'yatak', 'gardirop', 'dolap', 'vitrin', 'kitaplik', 'tv-unitesi',
        'konsol', 'sifonyer', 'calisma-masasi', 'ayakkabilik', 'portmanto',
    ];

    /** Seating, which faces the focal point rather than the nearest wall. */
    private const SEATING = ['kanepe', 'koltuk', 'berjer', 'kose-takimi', 'sedir'];

    /** Things that go on the floor under other things. */
    private const UNDERFOOT = ['hali', 'kilim', 'paspas'];

    /** Things that go in front of seating. */
    private const TABLES = ['sehpa', 'orta-sehpa', 'zigon'];

    /** Things that hang on a wall rather than standing on the floor. */
    private const WALL_HUNG = ['tablo', 'ayna', 'duvar-saati', 'raf'];

    /**
     * Positions a set of pieces in a room.
     *
     * @param  list<RoomConstraint>  $openings
     * @param  list<array{product_id: string, sku_id: string, category: string|null, width_mm: int, depth_mm: int, height_mm?: int|null, wall?: string|null}>  $pieces
     * @return array{items: list<array<string, mixed>>, unplaced: list<array<string, mixed>>}
     */
    public function compose(RoomGeometryVersion $geometry, array $openings, array $pieces): array
    {
        $state = new LayoutComposerState($geometry, $openings);

        $items = [];
        $unplaced = [];

        foreach ($this->inOrder($pieces) as $piece) {
            $placed = $this->place($piece, $state);

            if ($placed === null) {
                // Said rather than solved. Somewhere is not anywhere.
                $unplaced[] = ['product_id' => $piece['product_id'], 'category' => $piece['category']];

                continue;
            }

            $items[] = $placed;
            $state->occupy($placed);
        }

        return ['items' => $items, 'unplaced' => $unplaced];
    }

    // --- ordering --------------------------------------------------------------

    /**
     * The order pieces are placed in, which decides who gets the good wall.
     *
     * Rugs first because everything else stands on or beside them and a rug placed last is a
     * rug that has to be squeezed between things. Then the pieces that must be against a
     * wall, then seating, then what arranges itself around seating, then the small things,
     * and finally what hangs on a wall — which needs to know what is underneath it.
     *
     * @param  list<array<string, mixed>>  $pieces
     * @return list<array<string, mixed>>
     */
    private function inOrder(array $pieces): array
    {
        $rank = static function (array $piece): int {
            $category = (string) ($piece['category'] ?? '');

            return match (true) {
                in_array($category, self::UNDERFOOT, true) => 0,
                in_array($category, self::WALL_PIECES, true) => 1,
                in_array($category, self::SEATING, true) => 2,
                in_array($category, self::TABLES, true) => 3,
                in_array($category, self::WALL_HUNG, true) => 5,
                default => 4,
            };
        };

        // Within a rank, the widest first: the big pieces need the choice, and a wall filled
        // by three small ones is a wall a wardrobe can no longer use.
        usort($pieces, static function (array $a, array $b) use ($rank): int {
            return [$rank($a), -$a['width_mm']] <=> [$rank($b), -$b['width_mm']];
        });

        return $pieces;
    }

    // --- placement -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function place(array $piece, LayoutComposerState $state): ?array
    {
        $category = (string) ($piece['category'] ?? '');

        if (in_array($category, self::UNDERFOOT, true)) {
            return $this->placeUnderfoot($piece, $state);
        }

        if (in_array($category, self::WALL_HUNG, true)) {
            return $this->placeWallHung($piece, $state);
        }

        if (in_array($category, self::TABLES, true)) {
            return $this->placeTable($piece, $state) ?? $this->placeAgainstWall($piece, $state);
        }

        if (in_array($category, self::SEATING, true)) {
            return $this->placeSeating($piece, $state);
        }

        return $this->placeAgainstWall($piece, $state);
    }

    /**
     * A rug, centred on the room's free middle.
     *
     * Centred rather than under the sofa, because the sofa is not placed yet: a rug is the
     * first thing down precisely so everything else can be arranged on it.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>
     */
    private function placeUnderfoot(array $piece, LayoutComposerState $state): array
    {
        return $this->at($piece, $state->centreX(), $state->centreZ(), 0);
    }

    /**
     * Seating, facing the focal wall.
     *
     * Standing off the wall when the room can afford it. Sofas in a row against the walls of
     * a large room is a waiting area, not a living room — the plan says so in words and this
     * is the arithmetic of it.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeSeating(array $piece, LayoutComposerState $state): ?array
    {
        $wall = $this->wallFor($piece, $state);

        $depth = (int) $piece['depth_mm'];

        $roomDepth = in_array($wall, ['north', 'south'], true) ? $state->length() : $state->width();

        // Only floats in a room with the depth to spare. In a small room the extra 350 mm is
        // the walkway, and a customer who cannot get past their own sofa does not care that
        // it was a composition decision.
        $offset = $roomDepth >= self::FLOAT_THRESHOLD_MM
            ? self::FLOAT_MM + intdiv($depth, 2)
            : self::AGAINST_WALL_MM + intdiv($depth, 2);

        $along = $state->runAlong($wall, (int) $piece['width_mm'], centred: true);

        if ($along === null) {
            return null;
        }

        return $this->onWall($piece, $state, $wall, $along, $offset);
    }

    /**
     * A table, in front of whatever is already seating.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeTable(array $piece, LayoutComposerState $state): ?array
    {
        $seat = $state->lastSeating();

        if ($seat === null) {
            return null;
        }

        /*
         * In front of the seat means towards the middle of the room, whichever wall it faces.
         * Taking the direction from the seat's own rotation rather than from the wall it was
         * put against: a sofa somebody has since turned is still a sofa with a front.
         */
        $gap = self::TABLE_GAP_MM + intdiv((int) $seat['depth_mm'], 2) + intdiv((int) $piece['depth_mm'], 2);

        $x = (int) $seat['position_x_mm'];
        $z = (int) $seat['position_z_mm'];

        return match ((int) $seat['rotation_y_deg']) {
            0 => $this->at($piece, $x, $z + $gap, 0),
            180 => $this->at($piece, $x, $z - $gap, 0),
            90 => $this->at($piece, $x - $gap, $z, 0),
            270 => $this->at($piece, $x + $gap, $z, 0),
            default => null,
        };
    }

    /**
     * A picture, above whatever stands below it, or centred on a free wall.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeWallHung(array $piece, LayoutComposerState $state): ?array
    {
        $wall = $this->wallFor($piece, $state);

        $beneath = $state->tallestOn($wall);

        $along = $beneath !== null
            ? $state->alongOf($wall, $beneath)
            : $state->runAlong($wall, (int) $piece['width_mm'], centred: true);

        if ($along === null) {
            return null;
        }

        $placed = $this->onWall($piece, $state, $wall, $along, self::AGAINST_WALL_MM);

        if ($placed === null) {
            return null;
        }

        // Off the floor, which is what keeps it out of everything else's way: the collision
        // rules exempt anything standing above the floor from anything standing on it.
        $placed['position_y_mm'] = self::PICTURE_CENTRE_MM;

        return $placed;
    }

    /**
     * Anything that belongs against a wall: along the wall, after whatever is already there.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeAgainstWall(array $piece, LayoutComposerState $state): ?array
    {
        foreach ($this->wallsToTry($piece, $state) as $wall) {
            $along = $state->runAlong($wall, (int) $piece['width_mm'], centred: false);

            if ($along === null) {
                continue;
            }

            $placed = $this->onWall($piece, $state, $wall, $along, self::AGAINST_WALL_MM + intdiv((int) $piece['depth_mm'], 2));

            if ($placed !== null) {
                return $placed;
            }
        }

        return null;
    }

    // --- geometry --------------------------------------------------------------

    /**
     * Turns a position along a wall into coordinates, with the piece turned to face inwards.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function onWall(array $piece, LayoutComposerState $state, string $wall, int $along, int $offset): ?array
    {
        return match ($wall) {
            // Rotation is measured clockwise from "facing south", which is how a piece
            // against the north wall stands when it is looking into the room.
            'north' => $this->at($piece, $along, $offset, 0),
            'south' => $this->at($piece, $along, $state->length() - $offset, 180),
            'west' => $this->at($piece, $offset, $along, 90),
            'east' => $this->at($piece, $state->width() - $offset, $along, 270),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>
     */
    private function at(array $piece, int $x, int $z, int $rotation): array
    {
        return [
            'product_id' => $piece['product_id'],
            'sku_id' => $piece['sku_id'],
            'category' => $piece['category'] ?? null,
            'width_mm' => (int) $piece['width_mm'],
            'depth_mm' => (int) $piece['depth_mm'],
            'position_x_mm' => $x,
            'position_y_mm' => 0,
            'position_z_mm' => $z,
            'rotation_y_deg' => $rotation,
        ];
    }

    /**
     * The wall a piece asked for, if the room has one by that name.
     *
     * @param  array<string, mixed>  $piece
     */
    private function wallFor(array $piece, LayoutComposerState $state): string
    {
        $wall = $piece['wall'] ?? null;

        if (is_string($wall) && in_array($wall, ['north', 'south', 'east', 'west'], true)) {
            return $wall;
        }

        return $state->emptiestWall();
    }

    /**
     * Which walls to try, in order: the one asked for, then the emptiest of the rest.
     *
     * A second-best wall is worth trying. A wardrobe against the wrong wall is a wardrobe the
     * customer can drag to the right one in a second; a wardrobe that was never placed is a
     * product they have to find in the catalogue again.
     *
     * @param  array<string, mixed>  $piece
     * @return list<string>
     */
    private function wallsToTry(array $piece, LayoutComposerState $state): array
    {
        $first = $this->wallFor($piece, $state);

        return array_values(array_unique([$first, ...$state->wallsBySpace()]));
    }
}

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
 * So the rules here are ordinary, boring and explicit, and each one is the product contract's
 * (docs/product/ODA_STUDYOSU_KURALLARI.md, K13) by name:
 *
 *  - Odak — seating faces the focal wall: the television, else the widest window.
 *  - Dolaşım — the floor in front of a door stays empty, round the corner too; a sofa floats
 *    off its wall only when what faces it leaves the room to walk past.
 *  - Ölçek — standing furniture covers at most 40 % of the floor; a sofa takes at most two
 *    thirds of its wall. Past either, the piece is reported rather than squeezed in.
 *  - Sehpa kuralı — the coffee table sits 42 cm in front of the seat.
 *  - Halı kuralı — the rug goes down after the seating, its back edge under the front legs.
 *  - Simetri ve çift — bedside tables go either side of the bed; the television and the
 *    seating share a centre line because both are centred on facing walls.
 *  - Yükseklik — pictures hang at 1.5 m, sconces at 1.7 m, curtains from the floor on the
 *    window.
 *  - Aydınlatma — a floor lamp stands at the elbow of the seating.
 *
 * Style, palette and budget are choices about which products to bring, not where they stand;
 * they live in the design plan's brief and the product matcher, not here.
 *
 * Nothing here invents furniture. It positions exactly the pieces it is given — the ones the
 * customer's own catalogue matched — and reports the ones it could not place, with the rule
 * that stopped it, rather than finding somewhere for them. A layout that quietly puts a
 * wardrobe in the middle of the floor is worse than one that says it did not fit.
 */
final class LayoutComposer
{
    /** How far off a wall a piece stands when it is meant to be against it. */
    private const AGAINST_WALL_MM = 60;

    /** Sehpa kuralı: how far a coffee table sits from the seat in front of it (40–45 cm). */
    private const TABLE_GAP_MM = 420;

    /** How far seating floats off the wall in a room large enough for it to. */
    private const FLOAT_MM = 350;

    /**
     * Dolaşım: below this much clear depth — the room less whatever faces the seat — the room
     * cannot afford a floating seating group and everything hugs a wall.
     */
    private const FLOAT_THRESHOLD_MM = 3_800;

    /** Yükseklik: height a picture hangs at, centre above the floor. */
    private const PICTURE_CENTRE_MM = 1_500;

    /** Yükseklik: height a sconce sits at, centre above the floor. */
    private const SCONCE_CENTRE_MM = 1_700;

    /** Halı kuralı: how far under the seat's front edge the rug reaches. */
    private const RUG_UNDER_FRONT_MM = 200;

    /** Air between a piece and the one it stands beside. */
    private const BESIDE_GAP_MM = 80;

    /**
     * Oturma grubu: half a coffee table, for working out where the group closes.
     *
     * Seating is placed before tables — the table goes in front of the seat, so the seat has
     * to exist first — which means the wings of the group are positioned before anybody knows
     * how big the table will be. A nominal half-table is close enough: it decides where the
     * armchairs sit relative to the sofa, and a coffee table is 80 to 110 cm across in every
     * catalogue anybody sells from.
     */
    private const GROUP_TABLE_HALF_MM = 450;

    /** Ölçek: the share of the floor standing furniture may cover, in basis points. */
    private const FLOOR_SHARE_BPS = 4_000;

    /** Ölçek: the share of a wall a sofa may take, in basis points. */
    private const SEAT_WALL_SHARE_BPS = 6_667;

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
    private const SEATING = ['kanepe', 'koltuk', 'oturma-grubu', 'berjer', 'kose-takimi', 'sedir'];

    /** Things that go on the floor under other things. */
    private const UNDERFOOT = ['hali', 'kilim', 'paspas'];

    /** Things that go in front of seating. */
    private const TABLES = ['sehpa', 'orta-sehpa', 'zigon'];

    /** Things that hang on a wall rather than standing on the floor. */
    private const WALL_HUNG = ['tablo', 'ayna', 'duvar-saati', 'raf'];

    /** Lights that hang on a wall, higher than a picture. */
    private const SCONCES = ['duvar-aydinlatma', 'aplik'];

    /** Things that hang on the window. */
    private const CURTAINS = ['perde'];

    /** Things that stand either side of the bed. */
    private const BESIDE_BED = ['komodin'];

    /** Things that stand at the elbow of the seating. */
    private const BESIDE_SEATING = ['lambader'];

    /**
     * Simetri: wall pieces that take the middle of their wall rather than its first free
     * end — the bed, so a table fits either side of it; the television, so the seating
     * across from it lines up.
     */
    private const CENTRED_ON_WALL = ['yatak', 'tv-unitesi'];

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
            $reason = $state->takeRefusal();

            if ($placed === null) {
                // Said rather than solved, and said why. Somewhere is not anywhere.
                $unplaced[] = [
                    'product_id' => $piece['product_id'],
                    'category' => $piece['category'],
                    'reason' => $reason ?? 'no_room',
                ];

                continue;
            }

            $items[] = $placed;
            $state->occupy($placed);
        }

        return ['items' => $items, 'unplaced' => $unplaced];
    }

    /**
     * Where one more piece goes in a room that is already furnished.
     *
     * The same rules as {@see compose()}, which is the entire reason this exists rather than
     * the browser guessing. A customer who adds a bookcase to a room expects it against a
     * wall, because that is where bookcases go; the first free rectangle nearest the middle
     * of the floor is where nothing goes, and five products added that way stand in a heap in
     * the centre of the room with five centimetres between them.
     *
     * Returns null when there is nowhere for it. The editor then puts it in the middle and
     * lets the customer move it, which is honest: "it does not fit anywhere sensible" is
     * worth knowing and is not worth refusing an addition over.
     *
     * @param  list<RoomConstraint>  $openings
     * @param  list<array<string, mixed>>  $existing  what is already standing in the room
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    public function placeOne(
        RoomGeometryVersion $geometry,
        array $openings,
        array $existing,
        array $piece,
    ): ?array {
        $state = new LayoutComposerState($geometry, $openings);

        foreach ($existing as $item) {
            $state->occupy($item);
        }

        return $this->place($piece, $state);
    }

    // --- ordering --------------------------------------------------------------

    /**
     * The order pieces are placed in, which decides who gets the good wall.
     *
     * The pieces that must be against a wall first, then what stands beside the bed, then
     * seating, then what arranges itself around seating — the table in front, the lamp at
     * the elbow, the rug underneath — then the small things, and finally what hangs on a wall
     * or a window, which needs to know what is underneath it.
     *
     * @param  list<array<string, mixed>>  $pieces
     * @return list<array<string, mixed>>
     */
    private function inOrder(array $pieces): array
    {
        $rank = static function (array $piece): int {
            $category = (string) ($piece['category'] ?? '');

            return match (true) {
                in_array($category, self::WALL_PIECES, true) => 0,
                in_array($category, self::BESIDE_BED, true) => 1,
                in_array($category, self::SEATING, true) => 2,
                in_array($category, self::TABLES, true) => 3,
                in_array($category, self::BESIDE_SEATING, true) => 4,
                in_array($category, self::UNDERFOOT, true) => 5,
                in_array($category, self::WALL_HUNG, true), in_array($category, self::SCONCES, true) => 7,
                in_array($category, self::CURTAINS, true) => 8,
                default => 6,
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

        if (in_array($category, self::CURTAINS, true)) {
            return $this->placeCurtain($piece, $state);
        }

        if (in_array($category, self::SCONCES, true)) {
            return $this->placeWallHung($piece, $state, self::SCONCE_CENTRE_MM);
        }

        if (in_array($category, self::WALL_HUNG, true)) {
            return $this->placeWallHung($piece, $state, self::PICTURE_CENTRE_MM);
        }

        // Everything from here stands on the floor, and the floor is finite.
        if ($this->overScale($piece, $state)) {
            $state->refuse('scale');

            return null;
        }

        if (in_array($category, self::BESIDE_BED, true)) {
            return $this->placeBeside($piece, $state, $state->firstOf('yatak')) ?? $this->placeAgainstWall($piece, $state);
        }

        if (in_array($category, self::BESIDE_SEATING, true)) {
            return $this->placeBeside($piece, $state, $state->mainSeating()) ?? $this->placeAgainstWall($piece, $state);
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
     * Ölçek: whether this piece would take the standing furniture past its share of the floor.
     *
     * @param  array<string, mixed>  $piece
     */
    private function overScale(array $piece, LayoutComposerState $state): bool
    {
        $footprint = (int) $piece['width_mm'] * (int) $piece['depth_mm'];

        return ($state->occupiedFloorMm2() + $footprint) * 10_000 > $state->floorMm2() * self::FLOOR_SHARE_BPS;
    }

    /**
     * Halı kuralı: the rug under the seating group, reaching under its front legs.
     *
     * Centred on the seat and pushed out into the room so its back edge lies 200 mm under
     * the seat's front — the front legs stand on it, the back ones do not have to. With no
     * seating to serve, it is centred on the room.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>
     */
    private function placeUnderfoot(array $piece, LayoutComposerState $state): array
    {
        $seat = $state->mainSeating();

        if ($seat === null) {
            return $this->at($piece, $state->centreX(), $state->centreZ(), 0);
        }

        $rotation = $this->normalised((int) $seat['rotation_y_deg']);
        [$fx, $fz] = $this->forward($rotation);

        // From the seat's centre to the rug's: to the seat's front edge, back under it, then
        // half the rug's own depth.
        $reach = intdiv((int) $seat['depth_mm'], 2) - self::RUG_UNDER_FRONT_MM + intdiv((int) $piece['depth_mm'], 2);

        // The rug lies the way the seat does, so its width runs along the seat's width.
        $turned = $rotation === 90 || $rotation === 270;

        return $this->at(
            $piece,
            (int) $seat['position_x_mm'] + $fx * $reach,
            (int) $seat['position_z_mm'] + $fz * $reach,
            $turned ? 90 : 0,
        );
    }

    /**
     * Odak: seating, facing the focal wall.
     *
     * On the wall across from the focal one unless the plan asked for another; standing off
     * the wall when the room can afford it. Sofas in a row against the walls of a large room
     * is a waiting area, not a living room — the plan says so in words and this is the
     * arithmetic of it.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeSeating(array $piece, LayoutComposerState $state): ?array
    {
        /*
         * Oturma grubu: the second seat joins the first rather than taking a wall of its own.
         *
         * The plan for the product owner's living room said it in as many words — "oturma
         * grubu, duvarlara yapıştırılmak yerine odanın merkezinde bir 'ada' olarak
         * tasarlanmıştır", and the armchair "oturma grubunun kuzey kanadında, kanepeye dik,
         * diğer koltuğa bakacak şekilde". The composer read only the wall name beside it,
         * "north", and put the armchair against the north wall of the room, two and a half
         * metres from the sofa it was meant to be talking to.
         *
         * So a wall name on a secondary seat is a wing of the group, not a wall of the room,
         * and it is ignored here. If the group has no space for the chair, the wall rules
         * below take over and the wall name means what it says again.
         */
        $grouped = $this->placeInGroup($piece, $state);

        if ($grouped !== null) {
            return $grouped;
        }

        $wall = $this->askedWall($piece) ?? LayoutComposerState::opposite($state->focalWall());

        $width = (int) $piece['width_mm'];
        $depth = (int) $piece['depth_mm'];

        // Ölçek: a sofa longer than two thirds of its wall makes the wall a sofa.
        if ($width * 10_000 > $state->extentOf($wall) * self::SEAT_WALL_SHARE_BPS) {
            $state->refuse('scale');

            return null;
        }

        /*
         * Dolaşım: only floats in a room with the depth to spare once what faces it has taken
         * its share. In a small room the extra 350 mm is the walkway, and a customer who
         * cannot get past their own sofa does not care that it was a composition decision.
         */
        $roomDepth = in_array($wall, ['north', 'south'], true) ? $state->length() : $state->width();
        $clear = $roomDepth - $state->reachFrom(LayoutComposerState::opposite($wall));

        $offset = $clear >= self::FLOAT_THRESHOLD_MM
            ? self::FLOAT_MM + intdiv($depth, 2)
            : self::AGAINST_WALL_MM + intdiv($depth, 2);

        // Simetri: on the television's own line when there is one and the wall allows it;
        // otherwise the middle of the longest free run.
        $television = $state->firstOf('tv-unitesi');
        $along = null;

        if ($television !== null && $state->wallOf($television) === LayoutComposerState::opposite($wall)) {
            $line = $state->alongOf($wall, $television);
            $along = $state->fitsAlong($wall, $line, $width, $this->heightOf($piece)) ? $line : null;
        }

        $along ??= $state->runAlong($wall, $width, centred: true, heightMm: $this->heightOf($piece));

        if ($along === null) {
            return null;
        }

        return $this->onWall($piece, $state, $wall, $along, $offset);
    }

    /**
     * Oturma grubu: a second seat as a wing of the first, not a piece against a wall.
     *
     * The wings stand level with where the coffee table goes and just outside it, turned a
     * quarter so they look across the group at each other — which is what "kanepeye dik,
     * diğer koltuğa bakacak" describes, and what a room of people sitting together looks
     * like. Only a seat narrower than the main one joins: a second sofa the size of the first
     * is a second group, and that is a decision, not an arrangement.
     *
     * Null when there is no group to join or no room for a wing, and then the caller puts the
     * seat against a wall as before.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeInGroup(array $piece, LayoutComposerState $state): ?array
    {
        $seat = $state->mainSeating();

        if ($seat === null || (int) $piece['width_mm'] >= (int) $seat['width_mm']) {
            return null;
        }

        $rotation = $this->normalised((int) $seat['rotation_y_deg']);

        [$fx, $fz] = $this->forward($rotation);
        [$sx, $sz] = $this->sideways($rotation);

        // Forward to the table's line, so the group closes round the table rather than
        // trailing off behind the sofa.
        $reach = intdiv((int) $seat['depth_mm'], 2) + self::TABLE_GAP_MM + self::GROUP_TABLE_HALF_MM;

        // Sideways to just clear the table. The wing is turned a quarter, so what runs along
        // the group's width is its depth.
        $side = self::GROUP_TABLE_HALF_MM + self::BESIDE_GAP_MM + intdiv((int) $piece['depth_mm'], 2);

        $x = (int) $seat['position_x_mm'] + $fx * $reach;
        $z = (int) $seat['position_z_mm'] + $fz * $reach;

        foreach ($this->wings($state, $seat, $sx) as $sign) {
            $placed = $this->onFloor(
                $piece,
                $state,
                $x + $sx * $side * $sign,
                $z + $sz * $side * $sign,
                // Looking back across the group, which is where the other wing is.
                $this->facing(-$sx * $sign, -$sz * $sign),
            );

            if ($placed !== null && $this->insideRoom($placed, $state) && $state->standsClear($placed)) {
                return $placed;
            }
        }

        return null;
    }

    /**
     * Which wing of the group to try first: the one with more room behind it.
     *
     * A group that sits off-centre has a roomy side and a tight one. Filling the roomy side
     * first means one chair is comfortable and only the second has to squeeze, rather than
     * both being squeezed because the first took the wrong half.
     *
     * The second seat finds the first one standing there and takes the other wing, because a
     * wing already occupied fails the collision check.
     *
     * @param  array<string, mixed>  $seat
     * @return list<int>
     */
    private function wings(LayoutComposerState $state, array $seat, int $sx): array
    {
        $centre = $sx !== 0 ? (int) $seat['position_x_mm'] : (int) $seat['position_z_mm'];
        $extent = $sx !== 0 ? $state->width() : $state->length();

        return $centre * 2 > $extent ? [-1, 1] : [1, -1];
    }

    /**
     * The rotation that looks in a given direction — the inverse of {@see forward()}.
     */
    private function facing(int $dx, int $dz): int
    {
        return match (true) {
            $dz > 0 => 0,
            $dz < 0 => 180,
            $dx < 0 => 90,
            default => 270,
        };
    }

    /**
     * Sehpa kuralı: a table, 42 cm in front of whatever is already seating.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeTable(array $piece, LayoutComposerState $state): ?array
    {
        $seat = $state->mainSeating();

        if ($seat === null) {
            return null;
        }

        /*
         * In front of the seat means towards the middle of the room, whichever wall it faces.
         * Taking the direction from the seat's own rotation rather than from the wall it was
         * put against: a sofa somebody has since turned is still a sofa with a front.
         */
        $gap = self::TABLE_GAP_MM + intdiv((int) $seat['depth_mm'], 2) + intdiv((int) $piece['depth_mm'], 2);

        [$fx, $fz] = $this->forward($this->normalised((int) $seat['rotation_y_deg']));

        return $this->onFloor(
            $piece,
            $state,
            (int) $seat['position_x_mm'] + $fx * $gap,
            (int) $seat['position_z_mm'] + $fz * $gap,
            0,
        );
    }

    /**
     * Simetri ve çift, aydınlatma: a piece at the side of another — a bedside table by the
     * bed, a floor lamp at the sofa's elbow.
     *
     * The first goes to one side, the second to the other, so a pair is a pair. It lies the
     * way its companion does and stands as deep against the wall.
     *
     * @param  array<string, mixed>  $piece
     * @param  array<string, mixed>|null  $companion
     * @return array<string, mixed>|null
     */
    private function placeBeside(array $piece, LayoutComposerState $state, ?array $companion): ?array
    {
        if ($companion === null) {
            return null;
        }

        $rotation = $this->normalised((int) $companion['rotation_y_deg']);
        [$sx, $sz] = $this->sideways($rotation);

        $reach = intdiv((int) $companion['width_mm'], 2) + self::BESIDE_GAP_MM + intdiv((int) $piece['width_mm'], 2);

        // Odd ones to the one side, even ones to the other.
        $sign = $state->countOf((string) ($piece['category'] ?? '')) % 2 === 0 ? 1 : -1;

        // Flush with the companion's back, which is against the wall.
        [$fx, $fz] = $this->forward($rotation);
        $back = intdiv((int) $companion['depth_mm'], 2) - intdiv((int) $piece['depth_mm'], 2);

        foreach ([$sign, -$sign] as $side) {
            $placed = $this->onFloor(
                $piece,
                $state,
                (int) $companion['position_x_mm'] + $side * $sx * $reach - $fx * $back,
                (int) $companion['position_z_mm'] + $side * $sz * $reach - $fz * $back,
                $rotation,
            );

            if ($placed !== null && $this->insideRoom($placed, $state)) {
                return $placed;
            }
        }

        return null;
    }

    /**
     * Yükseklik: a picture or a sconce, above whatever stands below it, or centred on a free
     * wall, at the height its kind hangs at.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeWallHung(array $piece, LayoutComposerState $state, int $centreHeight): ?array
    {
        $wall = $this->askedWall($piece) ?? $state->emptiestWall();

        $beneath = $state->tallestOn($wall);

        $along = $beneath !== null
            ? $state->alongOf($wall, $beneath)
            : $state->runAlong($wall, (int) $piece['width_mm'], centred: true, heightMm: $this->heightOf($piece));

        if ($along === null) {
            return null;
        }

        $placed = $this->onWall($piece, $state, $wall, $along, self::AGAINST_WALL_MM);

        if ($placed === null) {
            return null;
        }

        // Off the floor, which is what keeps it out of everything else's way: the collision
        // rules exempt anything standing above the floor from anything standing on it.
        $placed['position_y_mm'] = $centreHeight;

        return $placed;
    }

    /**
     * Yükseklik: a curtain, on the widest window, from the floor.
     *
     * Centred on the glass rather than on a free run of wall — a curtain belongs to a window,
     * and a window with a sofa under it still has a curtain. Hung, so it takes no floor and
     * no wall run from anything standing there.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function placeCurtain(array $piece, LayoutComposerState $state): ?array
    {
        $window = $state->widestWindow();

        if ($window === null || $window->wall === null || $window->offset_mm === null || $window->width_mm === null) {
            return null;
        }

        $placed = $this->onWall(
            $piece,
            $state,
            $window->wall,
            $window->offset_mm + intdiv($window->width_mm, 2),
            intdiv((int) $piece['depth_mm'], 2),
        );

        if ($placed === null) {
            return null;
        }

        // Nominally above the floor so nothing treats it as furniture to walk around; it
        // still hangs to the floor in the scene.
        $placed['position_y_mm'] = 1;

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
        $centred = in_array((string) ($piece['category'] ?? ''), self::CENTRED_ON_WALL, true);

        foreach ($this->wallsToTry($piece, $state) as $wall) {
            $along = $state->runAlong($wall, (int) $piece['width_mm'], centred: $centred, heightMm: $this->heightOf($piece));

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
        /*
         * Rotation is clockwise seen from above, from "facing south" (+z): 90 faces west and
         * 270 faces east — the scene turns a piece by −rotation about y, and every model's
         * front is +z. A piece against a wall looks into the room.
         */
        return match ($wall) {
            'north' => $this->onFloor($piece, $state, $along, $offset, 0),
            'south' => $this->onFloor($piece, $state, $along, $state->length() - $offset, 180),
            'west' => $this->onFloor($piece, $state, $offset, $along, 270),
            'east' => $this->onFloor($piece, $state, $state->width() - $offset, $along, 90),
            default => null,
        };
    }

    /**
     * Dolaşım: a floor position, refused when it stands in front of a door.
     *
     * @param  array<string, mixed>  $piece
     * @return array<string, mixed>|null
     */
    private function onFloor(array $piece, LayoutComposerState $state, int $x, int $z, int $rotation): ?array
    {
        $placed = $this->at($piece, $x, $z, $rotation);

        if (! $state->clearOfDoors($placed)) {
            $state->refuse('doorway');

            return null;
        }

        return $placed;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function insideRoom(array $item, LayoutComposerState $state): bool
    {
        [$x0, $x1, $z0, $z1] = $state->boxOf($item);

        return $x0 >= 0 && $z0 >= 0 && $x1 <= $state->width() && $z1 <= $state->length();
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

    private function normalised(int $rotation): int
    {
        return ($rotation % 360 + 360) % 360;
    }

    /**
     * The unit step in front of a piece turned this way.
     *
     * @return array{int, int}
     */
    private function forward(int $rotation): array
    {
        return match ($rotation) {
            0 => [0, 1],
            180 => [0, -1],
            90 => [-1, 0],
            270 => [1, 0],
            default => [0, 1],
        };
    }

    /**
     * The unit step along a piece's width, for something standing beside it.
     *
     * @return array{int, int}
     */
    private function sideways(int $rotation): array
    {
        return $rotation === 90 || $rotation === 270 ? [0, 1] : [1, 0];
    }

    /**
     * How tall a piece is, when anybody measured it.
     *
     * Null rather than a guess: the wall runs treat an unknown height as tall, because a piece
     * nobody measured might be a wardrobe, and a wardrobe across a window is worse than a sofa
     * that did not fit.
     *
     * @param  array<string, mixed>  $piece
     */
    private function heightOf(array $piece): ?int
    {
        $height = $piece['height_mm'] ?? null;

        return is_int($height) && $height > 0 ? $height : null;
    }

    /**
     * The wall the plan asked for, if the room has one by that name.
     *
     * @param  array<string, mixed>  $piece
     */
    private function askedWall(array $piece): ?string
    {
        $wall = $piece['wall'] ?? null;

        return is_string($wall) && in_array($wall, ['north', 'south', 'east', 'west'], true) ? $wall : null;
    }

    /**
     * Which walls to try, in order: the one asked for, then the emptiest of the rest.
     *
     * A television with no wall asked for goes across from the widest window, so nobody
     * watches it against the light and the seating that faces it faces the window too.
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
        $first = $this->askedWall($piece);

        if ($first === null && (string) ($piece['category'] ?? '') === 'tv-unitesi') {
            $window = $state->windowWall();
            $first = $window === null ? null : LayoutComposerState::opposite($window);
        }

        return array_values(array_unique(array_filter([$first, ...$state->wallsBySpace()])));
    }
}

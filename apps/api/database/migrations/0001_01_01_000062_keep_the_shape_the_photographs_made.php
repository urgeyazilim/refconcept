<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A room's own shape, measured from its photographs rather than guessed from them.
 *
 * Six photographs of a room, handed to a reconstruction, come back as a quarter of a million
 * points in three dimensions. That is not a photograph and it is not a plate; it is the room's
 * shape, and it answers the question the reading has been guessing at — which wall is the long
 * one — by measuring instead of estimating. On the product owner's own room the reading gave
 * 3.8 × 4.5 m one time and 4.5 × 5.0 m the next; the reconstruction measures 1 : 1.43, which
 * is 3.9 × 5.6 m at a normal ceiling.
 *
 * Kept on the same private disk under the same rules as the photographs it was made from. A
 * point cloud of somebody's living room is their home as surely as a picture of it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE room_media DROP CONSTRAINT IF EXISTS room_media_type_check');
        DB::statement("ALTER TABLE room_media ADD CONSTRAINT room_media_type_check CHECK (type IN ('photo', 'floor_plan', 'inspiration', 'document', 'plate', 'scan'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM room_media WHERE type = 'scan'");
        DB::statement('ALTER TABLE room_media DROP CONSTRAINT IF EXISTS room_media_type_check');
        DB::statement("ALTER TABLE room_media ADD CONSTRAINT room_media_type_check CHECK (type IN ('photo', 'floor_plan', 'inspiration', 'document', 'plate'))");
    }
};

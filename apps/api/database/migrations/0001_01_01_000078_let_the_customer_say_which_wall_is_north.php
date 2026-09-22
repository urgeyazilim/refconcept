<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the room's four walls the customer calls north.
 *
 * The walls are named from the photographs, and the reading gets it wrong often enough that
 * the product owner asked to fix it by hand: their kitchen window faces east and the room
 * said west, so every sentence the planner wrote about light was backwards.
 *
 * A name rather than a turn. Rotating the room would be the other answer — move every opening
 * one wall along and swap the width and the length — and it is the right one when the room
 * itself is drawn the wrong way round. This is for when the room is drawn correctly and only
 * the compass is off, which is the common case and the one that can be fixed without touching
 * a single measurement: nothing moves, the four labels do.
 *
 * Stored as the drawn wall that faces north — `north` when the compass is right, `east` when
 * the wall we drew as east is the one that actually faces north. Everything else follows from
 * it clockwise, and every opening keeps the wall it is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('north_wall', 8)->default('north')->after('height_mm');
        });

        // The check is the same shape as the one on `room_constraints.wall`: the column is
        // where a stale client or a bad import would otherwise put "up".
        DB::statement(
            "alter table rooms add constraint rooms_north_wall_check check (north_wall in ('north', 'east', 'south', 'west'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table rooms drop constraint if exists rooms_north_wall_check');

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('north_wall');
        });
    }
};

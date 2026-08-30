<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a design plan the decisions an interior designer would actually make.
 *
 * What the plan held was an inventory with compass directions: "kanepe, south wall, 3000mm".
 * The renderer did exactly what it was told and produced exactly what you would expect —
 * every piece pushed flat against a wall, evenly spaced, symmetrical, lit by one ceiling
 * light. Furniture correctly placed in a room, and no design in it at all. Anybody could
 * cut those products out and paste them onto the photograph and get the same picture.
 *
 * The craft is in the decisions nothing was asking for:
 *
 *  - **The focal point.** Every room has one — a window with a view, a fireplace, the
 *    television wall, the bed's headboard — and everything else orients to it. A room
 *    without one reads as unresolved however good the furniture is.
 *  - **Zones, floated.** Seating belongs in a group about two to three metres across,
 *    pulled 30-45cm off the wall rather than flattened against it, so it reads as a place
 *    people sit rather than a waiting room.
 *  - **Circulation.** The path from the door, and 75-90cm of it, decided rather than
 *    whatever is left over.
 *  - **The composition itself.** Where the eye lands entering, where the height rises and
 *    falls, which wall is deliberately left breathing.
 *
 * So the plan gains two things. `composition` holds the room-level decisions — focal point,
 * the sightline from the door, the design moves in the designer's own words. And each
 * placement gains a `position` written as a relationship rather than a compass bearing:
 * "facing the window, floated 40cm off the wall, front legs on the rug" tells a renderer
 * something a wall name never can.
 *
 * `notes` already existed and stays what it was: whatever the model wanted to add. This is
 * structured because the render prompt reads it, and a paragraph is not something a prompt
 * can reliably take one fact out of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_plans', function (Blueprint $table): void {
            /*
             * Nullable, and it will stay nullable. Every plan written before this exists
             * without one and is still perfectly readable — a design from last month should
             * not become unopenable because the method improved.
             */
            $table->jsonb('composition')->nullable()->after('palette');
        });
    }

    public function down(): void
    {
        Schema::table('design_plans', function (Blueprint $table): void {
            $table->dropColumn('composition');
        });
    }
};

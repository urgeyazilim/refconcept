<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The foundation for planning a room in three dimensions rather than describing it in prose.
 *
 * Everything the design engine knows about where furniture goes is currently a sentence: "in
 * the middle of the room, facing the television unit, at least 40 cm clear of the wall". That
 * is what an interior designer writes and it is useless to a renderer, which is why the
 * renderer has been free to put things wherever the picture wanted them — and last week put a
 * sofa in a room that had none and moved the walls to fit it.
 *
 * Coordinates end that argument. A layout item is at x, y, z in millimetres from the corner
 * of the room, rotated by a known number of degrees, and either locked or not. The picture
 * has to agree with it, and once a depth map is derived from the same geometry the picture
 * will have no choice.
 *
 * Three tables, and one deliberate omission:
 *
 *  - **`room_geometry_versions`** — what the room measures, and who said so. The estimate the
 *    model made and the figures the customer confirmed are different things and are kept
 *    apart: a guess presented as a fact is how a wardrobe arrives that does not fit.
 *  - **`design_layouts`** — one arrangement of one room, versioned, with the AI's proposals
 *    and the customer's edits distinguishable forever after.
 *  - **`design_layout_items`** — a product at a position.
 *
 * The omission is openings. Doors and windows already live in `room_constraints` with a wall,
 * an offset and a size, which is exactly the shape a second table would have had. They are a
 * fact about the room rather than about one measurement of it, so they stay where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the room measures, per attempt.
         *
         * Versioned rather than overwritten because the interesting question is not only
         * "how big is this room" but "how did we come to believe that". A customer who
         * corrects the model's guess has told us something about the model; a customer who
         * accepts it has told us something too, and both are gone the moment a single row
         * is updated in place.
         */
        Schema::create('room_geometry_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->unsignedInteger('version');

            // 'ai' for an estimate, 'user' for figures somebody typed or accepted.
            $table->string('source', 12);

            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('length_mm');
            $table->unsignedInteger('height_mm');

            /*
             * How sure the model was, in basis points.
             *
             * Null for anything a person entered — a customer who measured their own room
             * with a tape is not 87% confident, they are simply right, and a number here
             * would invite a screen to show doubt where there is none.
             */
            $table->unsignedInteger('confidence_bps')->nullable();

            $table->uuid('analysis_id')->nullable();
            $table->foreign('analysis_id')->references('id')->on('room_analyses')->nullOnDelete();

            /*
             * Confirmation is the whole point of this table.
             *
             * An estimate is a proposal. Nothing is built on it — no 3D room, no layout, no
             * render — until somebody has looked at the numbers and said yes, because a
             * shopping list assembled against a guessed wall is a delivery van arriving at a
             * room that will not take the sofa.
             */
            $table->boolean('is_confirmed')->default(false);

            $table->uuid('confirmed_by')->nullable();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();

            // Whatever else the estimate carried: openings it proposed, warnings, the raw
            // structured answer. Kept whole so a later version can read more out of it than
            // this one knows to ask for.
            $table->jsonb('payload')->nullable();

            $table->timestampsTz();

            $table->unique(['room_id', 'version']);
            $table->index(['room_id', 'is_confirmed']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_geometry_versions
            ADD CONSTRAINT room_geometry_versions_source_check
            CHECK (source IN ('ai', 'user'))
        SQL);

        // A room is measured once at a time. Two confirmed versions is two answers to "how
        // wide is this wall", and every placement check would be free to pick either.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX room_geometry_versions_one_confirmed
            ON room_geometry_versions (room_id)
            WHERE is_confirmed
        SQL);

        // Nothing is zero millimetres across, and a room half a metre wide is a typo rather
        // than a room. Refused here so it cannot reach a 3D scene and look like a bug there.
        DB::statement(<<<'SQL'
            ALTER TABLE room_geometry_versions
            ADD CONSTRAINT room_geometry_versions_plausible_check
            CHECK (
                width_mm BETWEEN 1000 AND 30000
                AND length_mm BETWEEN 1000 AND 30000
                AND height_mm BETWEEN 1800 AND 6000
            )
        SQL);

        /*
         * One arrangement of one room.
         *
         * Anchored to the room rather than to a design, because planning comes first: a
         * customer arranges their living room and only then asks for a picture of it. The
         * design is attached when one is made, which is also what makes "this render came
         * from that layout" answerable later.
         */
        Schema::create('design_layouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->uuid('design_id')->nullable();
            $table->foreign('design_id')->references('id')->on('designs')->nullOnDelete();

            $table->uuid('geometry_version_id');
            $table->foreign('geometry_version_id')->references('id')->on('room_geometry_versions')->cascadeOnDelete();

            $table->unsignedInteger('version');

            /*
             * Who arranged it. Kept because "the AI did this" and "I did this" are different
             * claims about the same room, and a customer who dislikes a layout is telling us
             * about one of them — which one matters.
             */
            $table->string('source', 12);

            $table->string('status', 20)->default('draft');

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['room_id', 'version']);
            $table->index(['design_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_layouts
            ADD CONSTRAINT design_layouts_source_check
            CHECK (source IN ('ai', 'user'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE design_layouts
            ADD CONSTRAINT design_layouts_status_check
            CHECK (status IN ('draft', 'saved', 'rendered', 'archived'))
        SQL);

        /*
         * A product, somewhere in the room.
         *
         * Millimetres and whole degrees, both integers, for the same reason money is in minor
         * units: a position that drifts by a float's last bit between save and load is a sofa
         * that moves every time somebody opens the page.
         */
        Schema::create('design_layout_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('layout_id');
            $table->foreign('layout_id')->references('id')->on('design_layouts')->cascadeOnDelete();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            /*
             * The variant, and it is required rather than optional: a sofa is 2200 mm or
             * 2600 mm depending on which one was chosen, and a layout that knows only the
             * product knows neither. The dimensions this item occupies come from here.
             */
            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            // From the room's origin corner. `y` is height above the floor, which is zero for
            // everything that stands on it and not zero for anything hung on a wall.
            $table->integer('position_x_mm');
            $table->integer('position_y_mm')->default(0);
            $table->integer('position_z_mm');

            // Only the vertical axis. Furniture turns; it does not tumble, and offering three
            // axes is offering two ways to produce a sideboard lying on its face.
            $table->smallInteger('rotation_y_deg')->default(0);

            /*
             * Locked items are the contract between the customer and the layout engine.
             *
             * "Rearrange this but leave my sofa alone" is the single most common thing
             * somebody wants from a tool like this, and it is worthless unless the engine
             * genuinely cannot move it.
             */
            $table->boolean('locked')->default(false);

            // What the collision engine last thought of this position. Stored so a layout
            // reopened tomorrow shows its problems immediately rather than after a recompute.
            $table->string('collision_state', 12)->default('ok');

            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['layout_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_layout_items
            ADD CONSTRAINT design_layout_items_collision_check
            CHECK (collision_state IN ('ok', 'warning', 'blocked'))
        SQL);

        // Degrees, normalised. 370 and 10 are the same rotation and storing both means two
        // representations of one layout.
        DB::statement(<<<'SQL'
            ALTER TABLE design_layout_items
            ADD CONSTRAINT design_layout_items_rotation_check
            CHECK (rotation_y_deg >= 0 AND rotation_y_deg < 360)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('design_layout_items');
        Schema::dropIfExists('design_layouts');
        Schema::dropIfExists('room_geometry_versions');
    }
};

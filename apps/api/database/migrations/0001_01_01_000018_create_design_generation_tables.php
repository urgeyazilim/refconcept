<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the design engine produces on its way to an image.
 *
 * Generating a room is three steps, not one, and each leaves something worth keeping:
 *
 *  1. **Read the room.** `room_analyses` — what is actually in the photograph: the
 *     windows, the radiator, the floor that cannot be changed. Stored per photograph
 *     rather than per design, because the room does not change when somebody tries a
 *     second style, and re-reading it would be a second charge for the same answer.
 *  2. **Decide the layout.** `design_plans` — what goes where, before any pixels exist.
 *     Kept because it is what Phase 9 matches products against: "a sofa up to 2200mm on
 *     the south wall" is a search, and the image is not.
 *  3. **Draw it.** The render lands in `design_assets`, which already exists.
 *
 * `design_version_events` is the fourth table and the one a customer actually feels. A
 * render takes the better part of a minute, and a spinner that says nothing for fifty
 * seconds is indistinguishable from one that has hung. Each step writes a line, the
 * client polls, and the wait becomes something somebody is willing to sit through.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the model saw in one photograph.
         *
         * Keyed on the media rather than on the room: a customer who takes a better
         * photograph gets a new analysis, and the old one stays attached to the picture
         * it described. `is_current` marks the one the engine should use, enforced by a
         * partial unique index so a room cannot have two.
         *
         * The whole validated payload is kept in `payload`, and a handful of fields are
         * lifted out into columns. The columns are the ones something queries or
         * displays; the payload is what makes the analysis reconstructable when a later
         * phase wants a field nobody thought to extract.
         */
        Schema::create('room_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->uuid('media_id');
            $table->foreign('media_id')->references('id')->on('room_media')->cascadeOnDelete();

            $table->uuid('ai_job_id')->nullable();
            $table->foreign('ai_job_id')->references('id')->on('ai_jobs')->nullOnDelete();

            $table->string('detected_room_type', 40)->nullable();

            /*
             * Confidence in basis points, like every other rate in this system. A float
             * for a number that ends up beside a price is how the price becomes a float.
             */
            $table->unsignedInteger('confidence_bps')->nullable();

            $table->string('measurement_quality', 20)->nullable();

            $table->jsonb('payload');

            /*
             * Denormalised out of the payload because the design planner reads them on
             * every generation and a JSON path lookup per render is a cost with nothing
             * to show for it.
             */
            $table->jsonb('fixed_elements')->nullable();
            $table->jsonb('surfaces')->nullable();
            $table->jsonb('warnings')->nullable();

            $table->boolean('is_current')->default(true);

            $table->timestampsTz();

            $table->index(['room_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX room_analyses_one_current
            ON room_analyses (room_id)
            WHERE is_current
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE room_analyses
            ADD CONSTRAINT room_analyses_confidence_check
            CHECK (confidence_bps IS NULL OR confidence_bps BETWEEN 0 AND 10000)
        SQL);

        /*
         * The layout, decided before anything is drawn.
         *
         * One per version, and immutable once written — a plan that changed after its
         * image was produced would make the image unexplainable, and this is the row
         * that answers "why is there a sideboard there".
         *
         * `placements` is where the money is. Each entry names a category, a wall and a
         * maximum size in millimetres, which is exactly the shape Phase 9 turns into a
         * product search.
         */
        Schema::create('design_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id')->unique();
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            $table->uuid('ai_job_id')->nullable();
            $table->foreign('ai_job_id')->references('id')->on('ai_jobs')->nullOnDelete();

            $table->uuid('room_analysis_id')->nullable();
            $table->foreign('room_analysis_id')->references('id')->on('room_analyses')->nullOnDelete();

            $table->string('style', 60)->nullable();
            $table->jsonb('palette')->nullable();
            $table->jsonb('placements');
            $table->text('notes')->nullable();

            /*
             * Placements the planner proposed that the room cannot take — a 2200mm sofa
             * against a 2000mm wall. Recorded rather than silently dropped: a plan that
             * quietly loses a piece of furniture is a plan whose image will not match the
             * shopping list beside it.
             */
            $table->jsonb('rejected')->nullable();

            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_design_plans_immutable()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.placements IS DISTINCT FROM OLD.placements
                    OR NEW.style IS DISTINCT FROM OLD.style
                    OR NEW.palette IS DISTINCT FROM OLD.palette
                THEN
                    RAISE EXCEPTION 'a design plan cannot be rewritten; generate a new version';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER design_plans_no_rewrite
            BEFORE UPDATE ON design_plans
            FOR EACH ROW EXECUTE FUNCTION refconcept_design_plans_immutable();
        SQL);

        /*
         * Progress, as a customer experiences it.
         *
         * Append-only rows rather than a status column that gets overwritten, because
         * "it has been on 'görsel üretiliyor' for forty seconds" and "it went straight
         * there and stuck" are different problems and a single column cannot tell them
         * apart. It is also the difference between a spinner somebody waits out and one
         * they assume has hung.
         *
         * Deliberately free of anything sensitive: a stage, an outcome and a short
         * message in Turkish. No prompt, no photograph, nothing about the room.
         */
        Schema::create('design_version_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            $table->string('stage', 30);
            $table->string('status', 20);
            $table->string('message', 200);

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestampTz('created_at');

            $table->index(['design_version_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_version_events
            ADD CONSTRAINT design_version_events_stage_check
            CHECK (stage IN ('queued', 'analysis', 'plan', 'render', 'save', 'done'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE design_version_events
            ADD CONSTRAINT design_version_events_status_check
            CHECK (status IN ('started', 'succeeded', 'failed', 'skipped'))
        SQL);

        /*
         * Which render quality a version asked for, and the hold that paid for it.
         *
         * Quality is on the version rather than inferred from the route, because it is a
         * choice the customer made and the price they were quoted; a route changing
         * later must not rewrite what a version in the tree was.
         */
        Schema::table('design_versions', function (Blueprint $table): void {
            $table->string('render_quality', 20)->default('draft')->after('style_prompt');

            $table->uuid('credit_reservation_id')->nullable()->after('credit_cost');
            $table->foreign('credit_reservation_id')
                ->references('id')->on('credit_reservations')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_versions
            ADD CONSTRAINT design_versions_quality_check
            CHECK (render_quality IN ('draft', 'premium'))
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS design_plans_no_rewrite ON design_plans');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_design_plans_immutable()');

        Schema::table('design_versions', function (Blueprint $table): void {
            $table->dropForeign(['credit_reservation_id']);
            $table->dropColumn(['render_quality', 'credit_reservation_id']);
        });

        Schema::dropIfExists('design_version_events');
        Schema::dropIfExists('design_plans');
        Schema::dropIfExists('room_analyses');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions a room is designed by, and what each answer means in catalogue terms.
 *
 * The design brief was one blank textarea labelled "İstekleriniz". Almost nobody filled it
 * in — not for want of taste, but because "describe your living room" is a professional's
 * question asked of someone who has never had to answer it. People wrote "güzel olsun", or
 * nothing, and the engine downstream guessed. The model, given nothing, invented a
 * programme: a television unit, floor-length curtains, a framed picture, four cushions —
 * none of which the shop sells.
 *
 * Both halves of that failure have one cause, and one fix. Ask in pictures, offer only what
 * is in stock, and let the answers be the programme rather than the model's imagination.
 *
 * Data rather than code, and for a reason that matters more than tidiness: the catalogue
 * grows. When somebody finally lists a television unit, "ask about TV units in the living
 * room" should be a row an operator writes, not a deploy. Same for a question that turns
 * out to confuse people, or an option nobody ever picks.
 *
 * The shape is three levels:
 *
 *   programme   one per room type — salon, yatak odası, mutfak…
 *     question    "Oturma grubu nasıl olsun?"
 *       option      "Köşe koltuk"  → oturma-grubu ×1, needs a 2600mm wall
 *         categories    what the option actually asks the catalogue for
 *
 * Versioned, because a design has to be explicable a year later. "Why does my design have
 * two side tables" is answerable only if the questions that produced it are still readable
 * exactly as they were asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_programmes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The room this asks about. A string rather than a foreign key because room
            // types are an enum in code — there is no rooms taxonomy table to point at.
            $table->string('room_type');
            $table->unsignedSmallInteger('version');
            $table->string('status')->default('draft');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['room_type', 'version']);
            $table->index(['room_type', 'status']);
        });

        Schema::create('programme_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('programme_id');

            /*
             * A stable handle for the question, unique within its programme.
             *
             * Answers are stored against this rather than against the row id, so a design
             * from last spring still reads "seating: corner-sofa" after the question has
             * been reworded, re-ordered or re-versioned. An id would make old answers
             * unreadable the first time somebody edited a question.
             */
            $table->string('code');
            $table->string('prompt');
            $table->string('help')->nullable();

            // 'single' — one answer; 'multi' — several. Yes/no is a single with two options
            // rather than its own type, so every question renders the same way.
            $table->string('kind')->default('single');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['programme_id', 'code']);
            $table->foreign('programme_id')->references('id')->on('room_programmes')->cascadeOnDelete();
        });

        Schema::create('programme_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('question_id');
            $table->string('code');
            $table->string('label');
            $table->string('help')->nullable();

            /*
             * The picture on the tile.
             *
             * People choose between furniture by looking at it, not by reading about it —
             * which is the entire premise of replacing the textarea. Held as a short icon
             * name the client maps to its own artwork rather than a URL: an image path in
             * a seeded row is an asset pipeline problem in a data table, and the icon set
             * should be able to change without a migration.
             */
            $table->string('icon')->nullable();

            /*
             * The wall this option needs, in millimetres.
             *
             * A corner sofa in a room with no wall over 2600mm is an option that should be
             * shown greyed with the reason rather than offered and then quietly dropped by
             * the placement validator after the customer has chosen it.
             */
            $table->unsignedInteger('min_wall_mm')->nullable();

            // Chosen when the customer has expressed no preference, so a programme can be
            // completed by pressing through it.
            $table->boolean('is_default')->default(false);

            // "Şimdilik istemiyorum" — a real answer that asks for nothing. Distinguished
            // from an unanswered question, which is not the same thing at all.
            $table->boolean('is_none')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['question_id', 'code']);
            $table->foreign('question_id')->references('id')->on('programme_questions')->cascadeOnDelete();
        });

        Schema::create('programme_option_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('option_id');

            // The catalogue's own slug. Matched by slug rather than by id so a programme
            // can be written and reviewed as text, and so a seeder is readable.
            $table->string('category_slug');

            // Two side tables for "orta + yan sehpa" is one option asking for a category
            // twice, not two options.
            $table->unsignedSmallInteger('quantity')->default(1);

            /*
             * Whether the room still works without it.
             *
             * An optional piece that cannot be supplied is dropped quietly; a required one
             * that cannot be supplied is why the option is not offered at all. Without the
             * distinction, "üçlü kanepe + opsiyonel berjer" would be unavailable whenever
             * the shop had run out of armchairs.
             */
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->foreign('option_id')->references('id')->on('programme_options')->cascadeOnDelete();
            $table->index(['option_id', 'position']);
            $table->index('category_slug');
        });

        /*
         * What the customer chose, kept with the design.
         *
         * On the design rather than the room: two designs for one room are two different
         * briefs, and that is the point of having versions at all. Answers survive a
         * question being reworded because they are stored by code, and they carry the
         * programme version so a design remains explicable after the questions move on.
         */
        Schema::create('design_briefs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('design_version_id');
            $table->uuid('programme_id')->nullable();

            $table->string('style_code')->nullable();
            $table->string('palette_code')->nullable();
            $table->unsignedBigInteger('budget_minor')->nullable();

            // {"seating": ["three-seater"], "rug": ["large"], "decor": ["plant", "art"]}
            // — question code to chosen option codes, always a list so single and multi
            // read the same way.
            $table->jsonb('answers')->default('{}');

            // Whatever the customer still wanted to say in their own words. Optional, last,
            // and empty for most people — which is the whole point.
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();
            $table->foreign('programme_id')->references('id')->on('room_programmes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_briefs');
        Schema::dropIfExists('programme_option_categories');
        Schema::dropIfExists('programme_options');
        Schema::dropIfExists('programme_questions');
        Schema::dropIfExists('room_programmes');
    }
};

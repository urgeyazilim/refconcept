<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's home, as RefConcept understands it.
 *
 * This is the most private data in the system. A room photograph shows somebody's
 * living room: what they own, how they live, sometimes their family. It is worth more
 * to an intruder than a tax certificate and is protected accordingly — private disk,
 * random object keys, and access only through a short-lived signed URL issued after an
 * ownership check.
 *
 * Two structural decisions worth stating.
 *
 * **The original is immutable.** The photograph a customer uploads is never
 * overwritten, cropped in place or replaced by an AI render. Renders live in their own
 * table (`design_assets`), so there is no code path that could write over the original
 * even by mistake. A customer who dislikes a design must always be able to get back to
 * the room they actually have.
 *
 * **Designs are a tree, not a list.** Every version records the version it came from,
 * so "make the sofa darker" branches rather than overwrites. A customer comparing v3
 * against v1 is the normal case, not an edge case, and a flat list loses v1 the moment
 * v2 exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('project_type', 30)->default('home');
            $table->string('status', 20)->default('active');

            // Budget is an integer of minor units with its own currency, like every
            // other amount. A budget stored as a float is a budget that fails to add up
            // against the prices it is compared with.
            $table->string('currency', 3)->default('TRY');
            $table->bigInteger('budget_minor')->nullable();

            // Where the project is. Reuses the customer's address book rather than
            // duplicating an address, so correcting it in one place corrects it here.
            $table->uuid('address_id')->nullable();
            $table->foreign('address_id')->references('id')->on('user_addresses')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_type_check
            CHECK (project_type IN ('home', 'rental', 'office', 'hospitality', 'other'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_status_check
            CHECK (status IN ('draft', 'active', 'completed', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_budget_check
            CHECK (budget_minor IS NULL OR budget_minor >= 0)
        SQL);

        /*
         * People the owner has let in.
         *
         * A flat, a partner and an interior designer is the ordinary case, not an
         * enterprise feature. The owner is *not* a row here — they are
         * `projects.user_id` — so there is exactly one answer to "who owns this" and
         * no way for a project to end up ownerless by deleting a membership.
         */
        Schema::create('project_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            // Null until the invitation is accepted: somebody can be invited by e-mail
            // before they have an account at all.
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('invited_email', 255);
            $table->string('role', 20)->default('viewer');
            $table->string('status', 20)->default('invited');

            // Hashed, like every other credential in the system: the token in the
            // invitation e-mail is a bearer secret for somebody else's home.
            $table->string('invitation_token_hash', 255)->nullable();
            $table->timestampTz('invitation_expires_at')->nullable();

            $table->uuid('invited_by')->nullable();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->index(['project_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE project_members
            ADD CONSTRAINT project_members_role_check
            CHECK (role IN ('editor', 'viewer'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE project_members
            ADD CONSTRAINT project_members_status_check
            CHECK (status IN ('invited', 'active', 'revoked'))
        SQL);

        // One live membership per person per project. Inviting somebody twice is a
        // resend, not a second seat.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX project_members_one_live_per_email
            ON project_members (project_id, lower(invited_email))
            WHERE status <> 'revoked'
        SQL);

        Schema::create('project_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);

            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('changed_at');

            $table->index(['project_id', 'changed_at']);
        });

        /*
         * One room.
         *
         * The envelope — width, length, height — lives here rather than in a separate
         * one-to-one table: every room has exactly one set, and a join for a
         * one-to-one relationship buys nothing. What genuinely varies in number is
         * `room_constraints` below.
         *
         * `measurement_quality` is honest about where the numbers came from, because
         * the difference matters downstream: a design placed against an estimated wall
         * is a suggestion, one placed against a scanned wall is close to a promise.
         */
        Schema::create('rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('room_type', 40);

            $table->string('measurement_quality', 20)->default('unknown');
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            // The photograph the design engine works from. Set later, because a room is
            // usually created before its photograph is taken.
            $table->uuid('primary_media_id')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['project_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE rooms
            ADD CONSTRAINT rooms_measurement_quality_check
            CHECK (measurement_quality IN ('unknown', 'estimated', 'manual', 'scanned', 'verified'))
        SQL);

        /*
         * A room that claims to be measured must have measurements.
         *
         * Otherwise "manual" means nothing: a customer would see a quality badge on a
         * room with no numbers behind it, and the design engine would trust it.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE rooms
            ADD CONSTRAINT rooms_measured_has_dimensions_check
            CHECK (
                measurement_quality IN ('unknown', 'estimated')
                OR (width_mm IS NOT NULL AND length_mm IS NOT NULL)
            )
        SQL);

        /*
         * The customer's own photographs.
         *
         * Private disk, random key, never a public URL. `checksum_sha256` lets a
         * support conversation confirm the file being discussed is the file uploaded,
         * and makes an accidental duplicate upload obvious.
         */
        Schema::create('room_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->string('type', 20)->default('photo');
            $table->string('disk', 40);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum_sha256', 64);

            $table->string('caption', 300)->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->uuid('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['room_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_media
            ADD CONSTRAINT room_media_type_check
            CHECK (type IN ('photo', 'floor_plan', 'inspiration', 'document'))
        SQL);

        // The primary photograph has to be one of this room's own.
        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreign('primary_media_id')->references('id')->on('room_media')->nullOnDelete();
        });

        /*
         * What a design has to work around.
         *
         * Windows, doors, radiators, columns. Each is placed against a wall at an
         * offset, because "there is a window" is not enough to decide whether a
         * two-metre sofa fits: the design engine needs to know where.
         */
        Schema::create('room_constraints', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('label', 160)->nullable();
            $table->string('wall', 20)->nullable();

            // Distance from the left edge of that wall, looking at it from inside.
            $table->unsignedInteger('offset_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            // How far off the floor it starts — a radiator at 100 mm and a window at
            // 900 mm constrain completely different pieces of furniture.
            $table->unsignedInteger('sill_height_mm')->nullable();

            /*
             * Whether furniture may be placed in front of it. A column blocks; a socket
             * does not, but you still want to know where it is.
             */
            $table->boolean('is_blocking')->default(true);
            $table->boolean('must_stay_visible')->default(false);

            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index('room_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_constraints
            ADD CONSTRAINT room_constraints_type_check
            CHECK (type IN (
                'window', 'door', 'balcony_door', 'radiator', 'column', 'beam',
                'socket', 'switch', 'fixed_furniture', 'fireplace', 'stairs', 'other'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE room_constraints
            ADD CONSTRAINT room_constraints_wall_check
            CHECK (wall IS NULL OR wall IN ('north', 'east', 'south', 'west', 'ceiling', 'floor'))
        SQL);

        /*
         * A design: one customer's ongoing attempt at one room.
         *
         * The design is the container; the *versions* are the work. `current_version_id`
         * is what the customer is looking at, which is not necessarily the newest — the
         * point of a tree is being able to go back.
         */
        Schema::create('designs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('status', 20)->default('draft');

            $table->uuid('current_version_id')->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['room_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE designs
            ADD CONSTRAINT designs_status_check
            CHECK (status IN ('draft', 'generating', 'ready', 'failed', 'archived'))
        SQL);

        /*
         * One attempt, and where it came from.
         *
         * `parent_version_id` is the whole point. "Make the sofa darker" produces a
         * child of the version being looked at, not a replacement for it, so a customer
         * can always return to the one they liked. A null parent is a root: the first
         * attempt, or a deliberate fresh start from the original photograph.
         *
         * `credit_cost` is recorded on the version rather than only on the credit
         * ledger, so a customer looking at a version can be told what it cost without a
         * join into an accounting table — and so a refund can name the version.
         */
        Schema::create('design_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_id');
            $table->foreign('design_id')->references('id')->on('designs')->cascadeOnDelete();

            // The self-reference is added after the table exists: PostgreSQL cannot
            // point a foreign key at a table it is still in the middle of creating.
            $table->uuid('parent_version_id')->nullable();

            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('pending');

            $table->string('style_code', 60)->nullable();
            $table->text('style_prompt')->nullable();
            $table->text('user_prompt')->nullable();

            // Filled by the AI gateway from Phase 6; null while a version is pending.
            $table->uuid('ai_job_id')->nullable();
            $table->unsignedInteger('credit_cost')->default(0);

            $table->text('failure_reason')->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['design_id', 'version_number']);
            $table->index(['design_id', 'parent_version_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_versions
            ADD CONSTRAINT design_versions_status_check
            CHECK (status IN ('pending', 'generating', 'ready', 'failed'))
        SQL);

        /*
         * A finished version records when it finished; a failed one records why.
         * Otherwise "failed" is a status nobody can act on, and "ready" is a claim with
         * no timestamp behind it.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE design_versions
            ADD CONSTRAINT design_versions_terminal_check
            CHECK (
                (status <> 'ready' OR completed_at IS NOT NULL)
                AND (status <> 'failed' OR failure_reason IS NOT NULL)
            )
        SQL);

        // A version cannot descend from itself.
        DB::statement(<<<'SQL'
            ALTER TABLE design_versions
            ADD CONSTRAINT design_versions_no_self_parent_check
            CHECK (parent_version_id IS NULL OR parent_version_id <> id)
        SQL);

        Schema::table('design_versions', function (Blueprint $table): void {
            $table->foreign('parent_version_id')->references('id')->on('design_versions')->nullOnDelete();
        });

        Schema::table('designs', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('design_versions')->nullOnDelete();
        });

        /*
         * What a version produced.
         *
         * Separate from `room_media` on purpose, and that separation is the mechanism
         * behind "the original is immutable": there is no code path that could write an
         * AI render over the customer's own photograph, because the two live in
         * different tables with different writers.
         *
         * Private, like everything else here. A render of somebody's living room is as
         * revealing as the photograph it came from.
         */
        Schema::create('design_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            $table->string('type', 20)->default('render');
            $table->string('disk', 40);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum_sha256', 64);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['design_version_id', 'type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_assets
            ADD CONSTRAINT design_assets_type_check
            CHECK (type IN ('render', 'thumbnail', 'depth', 'mask', 'overlay'))
        SQL);

        // One render and one thumbnail per version: two would make "show me the design"
        // non-deterministic.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX design_assets_one_primary_per_type
            ON design_assets (design_version_id, type)
            WHERE type IN ('render', 'thumbnail') AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropForeign(['primary_media_id']);
        });

        Schema::dropIfExists('design_assets');
        Schema::dropIfExists('design_versions');
        Schema::dropIfExists('designs');
        Schema::dropIfExists('room_constraints');
        Schema::dropIfExists('room_media');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('project_status_history');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
    }
};

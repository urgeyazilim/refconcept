<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things an operator can change without a deploy.
 *
 * Two tables that look alike and are not. A **feature flag** answers "is this on", is
 * expected to change often, and is safe to get wrong for a moment. A **system setting** is
 * a value the platform runs on — a commission default, a hold period, a support address —
 * and getting one wrong is a bad afternoon.
 *
 * So a setting carries a type and is validated against it, and both carry who changed them
 * last. Every change is also audited: the row says what it is now, and the audit log says
 * what it was and who decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * On, off, or on for some.
         *
         * `rollout_percentage` rather than a list of accounts: a flag is for finding out
         * whether something works, and the answer is more honest from a slice of everybody
         * than from whoever was added to a list. The bucket is computed from a stable hash
         * of the user id, so a person who has the feature keeps it.
         */
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('key', 80);
            $table->string('name', 160);
            $table->string('description', 300)->nullable();

            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('rollout_percentage')->default(100);

            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE feature_flags
            ADD CONSTRAINT feature_flags_rollout_check
            CHECK (rollout_percentage <= 100)
        SQL);

        DB::statement('CREATE UNIQUE INDEX feature_flags_key_unique ON feature_flags (key)');

        /*
         * A value the platform runs on.
         *
         * Typed, because "14" and "true" and "destek@refconcept.com" are not the same kind
         * of thing and a settings screen that accepts any string into any field is a
         * settings screen that will one day set a hold period to "yes".
         */
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('key', 120);
            $table->string('group', 60)->default('general');
            $table->string('label', 160);
            $table->string('description', 300)->nullable();

            $table->string('type', 20)->default('string');
            $table->text('value')->nullable();

            /*
             * Whether the value may be shown once it is set.
             *
             * A support address is public; an API token is not, and a settings screen that
             * echoes one back has published it to everybody who can open the page.
             */
            $table->boolean('is_secret')->default(false);

            $table->uuid('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index('group');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE system_settings
            ADD CONSTRAINT system_settings_type_check
            CHECK (type IN ('string', 'integer', 'boolean', 'json'))
        SQL);

        DB::statement('CREATE UNIQUE INDEX system_settings_key_unique ON system_settings (key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('feature_flags');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations are the tenant boundary.
 *
 * A seller (Phase 2) belongs to an organization, and organization membership is what
 * makes "seller A cannot read seller B" enforceable in one place instead of scattered
 * across every query. Platform staff are not members of any organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->string('type', 30)->default('seller');
            $table->string('status', 30)->default('active');

            $table->uuid('owner_user_id')->nullable();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('status');
            $table->index('type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
            ADD CONSTRAINT organizations_type_check
            CHECK (type IN ('seller', 'professional', 'internal'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE organizations
            ADD CONSTRAINT organizations_status_check
            CHECK (status IN ('pending', 'active', 'suspended', 'closed'))
        SQL);

        Schema::create('organization_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            $table->string('status', 30)->default('active');
            $table->uuid('invited_by')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('removed_at')->nullable();

            $table->timestampsTz();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE organization_users
            ADD CONSTRAINT organization_users_status_check
            CHECK (status IN ('invited', 'active', 'suspended', 'removed'))
        SQL);

        // user_roles.organization_id could not be constrained when it was created,
        // because organizations did not exist yet.
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
        });

        Schema::dropIfExists('organization_users');
        Schema::dropIfExists('organizations');
    }
};

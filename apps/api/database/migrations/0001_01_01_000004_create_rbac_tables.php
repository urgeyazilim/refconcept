<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control.
 *
 * Permissions are fine-grained strings ("orders.refund"), roles group them, and users
 * hold roles. Roles can be scoped: a platform role applies everywhere, an organization
 * role applies only inside one organization — which is how seller staff get authority
 * over their own seller and nothing else.
 *
 * Authorization is still decided by Policies; these tables only carry the grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->string('group', 60);
            $table->string('description', 255)->nullable();
            $table->timestampsTz();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('scope', 20)->default('platform');
            $table->string('description', 255)->nullable();

            // A system role is created by migration/seed and cannot be deleted or renamed
            // through the admin UI; removing one would silently strip access at runtime.
            $table->boolean('is_system')->default(false);

            $table->timestampsTz();

            $table->unique(['slug', 'scope']);
            $table->index('scope');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roles
            ADD CONSTRAINT roles_scope_check
            CHECK (scope IN ('platform', 'organization'))
        SQL);

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->uuid('permission_id');

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('role_id');

            // Null for platform roles; set for roles granted inside one organization.
            $table->uuid('organization_id')->nullable();

            $table->uuid('granted_by')->nullable();
            $table->timestampTz('granted_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'organization_id']);
            $table->index('role_id');
        });

        // One grant of a given role per scope. Two identical grants would make revocation
        // ambiguous — revoking one would leave the user's access unchanged.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_roles_unique_platform_grant
            ON user_roles (user_id, role_id)
            WHERE organization_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX user_roles_unique_organization_grant
            ON user_roles (user_id, role_id, organization_id)
            WHERE organization_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};

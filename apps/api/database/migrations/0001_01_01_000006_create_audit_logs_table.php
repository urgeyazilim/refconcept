<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail.
 *
 * High-risk admin actions (refunds, payouts, commission overrides, seller
 * reactivation, manual ledger adjustments) must be answerable later: who did it, when,
 * from where, against what, and why. Rows are never updated or deleted — a mistaken
 * entry is followed by a corrective entry, exactly like the financial ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 30)->default('user');
            $table->string('actor_label', 200)->nullable();

            $table->string('action', 120);
            $table->string('auditable_type', 120)->nullable();
            $table->uuid('auditable_id')->nullable();

            $table->uuid('organization_id')->nullable();

            $table->jsonb('changes')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('reason', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
            ADD CONSTRAINT audit_logs_actor_type_check
            CHECK (actor_type IN ('user', 'system', 'job', 'webhook', 'console'))
        SQL);

        // Immutability is enforced by the database, not by convention: application bugs,
        // ad-hoc SQL and future maintainers all hit the same wall.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_audit_logs_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs rows are immutable (attempted %)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION refconcept_audit_logs_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_audit_logs_immutable()');

        Schema::dropIfExists('audit_logs');
    }
};

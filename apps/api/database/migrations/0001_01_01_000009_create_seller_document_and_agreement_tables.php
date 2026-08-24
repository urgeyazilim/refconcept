<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents, agreements and the onboarding trail.
 *
 * Agreements are versioned and acceptances are append-only: proving a seller agreed
 * later means proving *which text* they agreed to, on what date, from what address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('original_name', 255);

            // Object storage key on the private disk. Never a public URL: these are tax
            // certificates and signature circulars, reachable only by signed link.
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);

            $table->string('status', 30)->default('pending');
            $table->string('review_note', 500)->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->uuid('uploaded_by');
            $table->foreign('uploaded_by')->references('id')->on('users')->cascadeOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['application_id', 'type']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_documents
            ADD CONSTRAINT seller_documents_type_check
            CHECK (type IN (
                'tax_certificate', 'trade_registry_gazette', 'signature_circular',
                'identity_document', 'bank_account_proof', 'activity_certificate', 'other'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE seller_documents
            ADD CONSTRAINT seller_documents_status_check
            CHECK (status IN ('pending', 'approved', 'rejected'))
        SQL);

        Schema::create('seller_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 60);
            $table->string('version', 40);
            $table->string('title', 200);
            $table->text('body');
            $table->timestampTz('effective_from');
            $table->boolean('is_mandatory')->default(true);

            $table->timestampsTz();

            $table->unique(['code', 'version']);
            $table->index('effective_from');
        });

        Schema::create('seller_agreement_acceptances', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->uuid('agreement_id');
            $table->foreign('agreement_id')->references('id')->on('seller_agreements')->restrictOnDelete();

            $table->uuid('accepted_by');
            $table->foreign('accepted_by')->references('id')->on('users')->cascadeOnDelete();

            $table->timestampTz('accepted_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // The exact text hash at acceptance time. If the stored agreement body were
            // ever edited, this proves what was actually on screen.
            $table->char('body_checksum', 64);

            $table->timestampsTz();

            $table->unique(['application_id', 'agreement_id']);
        });

        // Acceptances are evidence; correcting one means publishing a new agreement
        // version and recording a fresh acceptance, never editing history.
        DB::unprepared(
            'CREATE OR REPLACE FUNCTION refconcept_acceptances_immutable() '
            .'RETURNS TRIGGER AS $func$ '
            .'BEGIN '
            ."RAISE EXCEPTION 'seller_agreement_acceptances rows are immutable (attempted %)', TG_OP; "
            .'END; '
            .'$func$ LANGUAGE plpgsql;'
        );

        DB::unprepared(
            'CREATE TRIGGER seller_agreement_acceptances_no_update '
            .'BEFORE UPDATE OR DELETE ON seller_agreement_acceptances '
            .'FOR EACH ROW EXECUTE FUNCTION refconcept_acceptances_immutable();'
        );

        Schema::create('seller_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->uuid('application_id')->nullable();
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 1000)->nullable();

            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('changed_at')->useCurrent();

            $table->index(['seller_id', 'changed_at']);
            $table->index(['application_id', 'changed_at']);
        });

        Schema::create('seller_onboarding_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('step', 40);
            $table->boolean('completed')->default(false);
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['application_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_onboarding_steps');
        Schema::dropIfExists('seller_status_history');

        DB::unprepared('DROP TRIGGER IF EXISTS seller_agreement_acceptances_no_update ON seller_agreement_acceptances');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_acceptances_immutable()');

        Schema::dropIfExists('seller_agreement_acceptances');
        Schema::dropIfExists('seller_agreements');
        Schema::dropIfExists('seller_documents');
    }
};

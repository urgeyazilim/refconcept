<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller onboarding (Phase 2).
 *
 * An application is what a prospective seller fills in; a `sellers` row is what an
 * approval creates. Keeping them apart means a rejected application stays on record
 * with its reason, and an approved one keeps the evidence of what was approved.
 *
 * Everything a seller owns hangs off `organization_id`, which is the tenant boundary
 * established in Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('applicant_user_id');
            $table->foreign('applicant_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Set when the application is approved and an organization is created.
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();

            $table->string('company_name', 200);
            $table->string('display_name', 160);
            $table->string('legal_form', 40);
            $table->string('contact_email');
            $table->string('contact_phone', 32);
            $table->string('website', 255)->nullable();
            $table->text('product_categories')->nullable();

            $table->string('status', 30)->default('draft');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->string('decision_reason', 1000)->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index(['applicant_user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_applications
            ADD CONSTRAINT seller_applications_status_check
            CHECK (status IN ('draft', 'submitted', 'in_review', 'approved', 'rejected', 'withdrawn'))
        SQL);

        // A decision must carry a reason. An approval or rejection nobody can explain
        // later is exactly what the audit requirements exist to prevent.
        DB::statement(<<<'SQL'
            ALTER TABLE seller_applications
            ADD CONSTRAINT seller_applications_decision_reason_check
            CHECK (status NOT IN ('approved', 'rejected') OR decision_reason IS NOT NULL)
        SQL);

        // One live application per applicant: a second draft while one is under review
        // makes "which one did we approve" unanswerable.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX seller_applications_one_open_per_applicant
            ON seller_applications (applicant_user_id)
            WHERE status IN ('draft', 'submitted', 'in_review')
        SQL);

        Schema::create('sellers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->uuid('application_id')->nullable();
            $table->foreign('application_id')->references('id')->on('seller_applications')->nullOnDelete();

            $table->string('seller_code', 40)->unique();
            $table->string('display_name', 160);

            $table->string('status', 30)->default('active');
            $table->string('onboarding_status', 30)->default('completed');
            $table->string('risk_status', 30)->default('normal');

            /*
             * Commission in basis points, never a float or a percentage string:
             * 1250 bps = 12.5%. Integers make the arithmetic exact and the intent
             * unambiguous (05_ARCHITECTURE_AND_CODE_RULES.md, "Money").
             */
            $table->integer('default_commission_bps')->nullable();

            // Gateway identifiers are credentials in effect; encrypted at rest.
            $table->text('iyzico_submerchant_key')->nullable();
            $table->text('qnb_merchant_reference')->nullable();

            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('suspended_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('organization_id');
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sellers
            ADD CONSTRAINT sellers_status_check
            CHECK (status IN ('active', 'suspended', 'closed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE sellers
            ADD CONSTRAINT sellers_commission_bps_check
            CHECK (default_commission_bps IS NULL OR (default_commission_bps >= 0 AND default_commission_bps <= 10000))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('seller_applications');
    }
};

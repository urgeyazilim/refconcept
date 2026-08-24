<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The detail a seller supplies during onboarding.
 *
 * These tables hang off the application rather than the seller, because they are
 * filled in before approval and must survive a rejection as the record of what was
 * reviewed. On approval the application id is carried onto the seller row, so the
 * whole file stays reachable from either side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_legal_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('legal_name', 250);
            $table->string('tax_office', 120)->nullable();

            // VKN is 10 digits, TCKN is 11; both are stored as text because they are
            // identifiers, not numbers — leading zeros matter and arithmetic never does.
            $table->string('tax_number', 20)->nullable();
            $table->string('national_id', 20)->nullable();
            $table->string('mersis_number', 20)->nullable();
            $table->string('trade_registry_number', 40)->nullable();
            $table->string('kep_address', 160)->nullable();

            $table->timestampsTz();

            $table->unique('application_id');
        });

        Schema::create('seller_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('full_name', 160);
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('title', 120)->nullable();

            $table->timestampsTz();

            $table->index(['application_id', 'type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_contacts
            ADD CONSTRAINT seller_contacts_type_check
            CHECK (type IN ('primary', 'finance', 'logistics', 'technical', 'legal'))
        SQL);

        Schema::create('seller_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('type', 30)->default('registered');
            $table->char('country_code', 2)->default('TR');
            $table->string('city', 120);
            $table->string('district', 120)->nullable();
            $table->string('address_line1', 255);
            $table->string('address_line2', 255)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestampsTz();

            $table->index(['application_id', 'type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_addresses
            ADD CONSTRAINT seller_addresses_type_check
            CHECK (type IN ('registered', 'warehouse', 'billing', 'return'))
        SQL);

        Schema::create('seller_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('account_holder', 200);
            $table->string('bank_name', 120)->nullable();

            /*
             * The IBAN is encrypted at rest: it is the destination of every payout, so
             * a leaked table would be an instruction sheet for redirecting money.
             * `iban_last4` and `iban_fingerprint` exist so the UI can show a masked
             * value and duplicates can be detected without ever decrypting.
             */
            $table->text('iban_encrypted');
            $table->char('iban_last4', 4);
            $table->char('iban_fingerprint', 64);

            $table->char('currency', 3)->default('TRY');
            $table->boolean('is_primary')->default(true);

            $table->timestampsTz();

            $table->index('application_id');
            $table->index('iban_fingerprint');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX seller_bank_accounts_one_primary
            ON seller_bank_accounts (application_id)
            WHERE is_primary
        SQL);

        Schema::create('seller_tax_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->foreign('application_id')->references('id')->on('seller_applications')->cascadeOnDelete();

            $table->string('taxpayer_type', 30);
            $table->integer('default_vat_rate_bps')->default(2000);
            $table->boolean('e_invoice_enabled')->default(false);
            $table->boolean('e_archive_enabled')->default(false);

            $table->timestampsTz();

            $table->unique('application_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_tax_profiles
            ADD CONSTRAINT seller_tax_profiles_type_check
            CHECK (taxpayer_type IN ('corporate', 'sole_proprietor', 'individual'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE seller_tax_profiles
            ADD CONSTRAINT seller_tax_profiles_vat_check
            CHECK (default_vat_rate_bps >= 0 AND default_vat_rate_bps <= 10000)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_tax_profiles');
        Schema::dropIfExists('seller_bank_accounts');
        Schema::dropIfExists('seller_addresses');
        Schema::dropIfExists('seller_contacts');
        Schema::dropIfExists('seller_legal_entities');
    }
};

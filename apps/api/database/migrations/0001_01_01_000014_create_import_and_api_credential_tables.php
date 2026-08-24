<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk import and machine access.
 *
 * A seller with four hundred products will not type them in, so the import path is
 * not a convenience — it is how the catalogue gets populated at all. Two properties
 * make it survivable:
 *
 *  1. **Every row is stored.** The file is parsed once into `import_rows`, and every
 *     later step reads those rows rather than the file. A seller can see exactly which
 *     of their 400 lines failed and why, months later, without the original file.
 *  2. **Dry run first, always.** An import that writes as it validates leaves the
 *     catalogue half-changed when line 250 turns out to be malformed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->uuid('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers')->nullOnDelete();

            $table->string('type', 30)->default('products');
            $table->string('status', 20)->default('uploaded');

            $table->string('original_name', 255);
            $table->string('disk', 40);
            $table->string('storage_path', 500);
            $table->unsignedBigInteger('size_bytes');

            // The header row as found, and the seller's column → field decisions.
            $table->jsonb('detected_headers')->nullable();
            $table->jsonb('mapping')->nullable();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);

            $table->text('failure_reason')->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('analysed_at')->nullable();
            $table->timestampTz('dry_run_at')->nullable();
            $table->timestampTz('committed_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_batches
            ADD CONSTRAINT import_batches_status_check
            CHECK (status IN ('uploaded', 'analysing', 'mapped', 'validating', 'validated', 'importing', 'completed', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE import_batches
            ADD CONSTRAINT import_batches_type_check
            CHECK (type IN ('products', 'prices', 'stock'))
        SQL);

        /*
         * A committed batch must record when it finished, and a failed one must say
         * why. Otherwise "failed" is a status nobody can act on.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE import_batches
            ADD CONSTRAINT import_batches_terminal_check
            CHECK (
                (status <> 'completed' OR committed_at IS NOT NULL)
                AND (status <> 'failed' OR failure_reason IS NOT NULL)
            )
        SQL);

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('batch_id');
            $table->foreign('batch_id')->references('id')->on('import_batches')->cascadeOnDelete();

            // The line number in the seller's file, header included, so an error
            // message can say "satır 251" and mean the line they can actually see.
            $table->unsignedInteger('line_number');

            $table->jsonb('raw');
            $table->jsonb('normalised')->nullable();

            $table->string('status', 20)->default('pending');
            $table->jsonb('errors')->nullable();

            // What the row turned into, once it did.
            $table->string('action', 20)->nullable();
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->uuid('sku_id')->nullable();
            $table->foreign('sku_id')->references('id')->on('product_skus')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['batch_id', 'line_number']);
            $table->index(['batch_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_rows
            ADD CONSTRAINT import_rows_status_check
            CHECK (status IN ('pending', 'valid', 'invalid', 'imported', 'skipped'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE import_rows
            ADD CONSTRAINT import_rows_action_check
            CHECK (action IS NULL OR action IN ('create', 'update', 'skip'))
        SQL);

        // An invalid row without errors would be a dead end for the seller.
        DB::statement(<<<'SQL'
            ALTER TABLE import_rows
            ADD CONSTRAINT import_rows_errors_check
            CHECK (status <> 'invalid' OR errors IS NOT NULL)
        SQL);

        /*
         * Machine credentials for a seller's own systems.
         *
         * The secret is hashed, never stored, and shown exactly once at creation —
         * the same contract as a password, for the same reason. The public identifier
         * is separate so a request can be attributed and rate-limited before the
         * expensive hash comparison happens.
         */
        Schema::create('api_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->string('name', 160);

            // Public half: safe to log, safe to show, useless on its own.
            $table->string('key_id', 40)->unique();

            // Private half: hashed. A leaked database does not hand over live keys.
            $table->string('secret_hash', 255);

            // Last four characters, so a seller can tell two credentials apart.
            $table->string('secret_hint', 8);

            $table->jsonb('scopes');

            $table->unsignedInteger('rate_limit_per_minute')->default(120);

            $table->timestampTz('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 300)->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['organization_id', 'revoked_at']);
        });

        // Revoking must say why: an unexplained dead credential is a support ticket.
        DB::statement(<<<'SQL'
            ALTER TABLE api_credentials
            ADD CONSTRAINT api_credentials_revocation_check
            CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL)
        SQL);

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('credential_id')->nullable();
            $table->foreign('credential_id')->references('id')->on('api_credentials')->nullOnDelete();

            $table->string('method', 10);
            $table->string('path', 300);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();

            $table->timestampTz('created_at');

            $table->index(['credential_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_credentials');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};

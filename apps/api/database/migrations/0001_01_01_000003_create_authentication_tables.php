<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authentication surface: API tokens, verification, password reset, login forensics.
 *
 * Every secret in these tables is stored **hashed**. A database leak must not hand
 * an attacker a working verification link, reset link or API token.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sanctum's table, redefined with UUID owners to match our identity schema.
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampsTz();

            $table->index('expires_at');
        });

        Schema::create('email_verification_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('email');
            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestampsTz();

            $table->unique('token_hash');
            $table->index(['user_id', 'consumed_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampsTz();

            $table->unique('token_hash');
            $table->index(['user_id', 'consumed_at']);
        });

        /**
         * Login attempts feed rate limiting, lockout and suspicious-signup detection.
         * The identifier is stored as typed (not resolved to a user) so attempts against
         * non-existent accounts are recorded too.
         */
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('identifier');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason', 60)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['identifier', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        /**
         * A session is one authenticated device/token lifetime. It exists next to
         * personal_access_tokens so a user can review and revoke devices even after a
         * token row is pruned, and so security review has a durable trail.
         */
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->uuid('token_id')->nullable();
            $table->string('device_name', 160)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('ended_reason', 40)->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'ended_at']);
            $table->index('token_id');
        });

        /**
         * KVKK / consent records. Consent is versioned and append-only: withdrawing
         * consent inserts a new row rather than editing the historical acceptance.
         */
        Schema::create('consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('version', 40);
            $table->boolean('granted')->default(true);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'type', 'recorded_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE consents
            ADD CONSTRAINT consents_type_check
            CHECK (type IN ('privacy_notice', 'terms', 'marketing', 'cookies', 'data_transfer'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('email_verification_tokens');
        Schema::dropIfExists('personal_access_tokens');
    }
};

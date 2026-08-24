<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI gateway.
 *
 * RefConcept is not an image generator with a marketplace attached; it is an
 * orchestration layer that happens to call models. The whole point of these tables is
 * that no model name appears anywhere in the code. An operator chooses which provider
 * serves which task, with what timeout, how many retries, what it may cost and which
 * prompt version to use — and changing any of that is configuration, not a deploy.
 *
 * Three things are recorded for every single call, and each exists because of a
 * question somebody will ask:
 *
 *  - **What did we send?** `ai_requests`, with the prompt version, so "why did it
 *    answer that" is answerable months later against the exact text used.
 *  - **What did it cost?** `ai_usage`, in millionths of a currency unit, because a
 *    token costs far less than a cent and rounding to cents would make every cost
 *    report zero.
 *  - **Why did it fail?** `ai_failures`, with a kind rather than only a message,
 *    because "retry this" and "stop retrying this" are different decisions and a
 *    string cannot be branched on safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 40)->unique();
            $table->string('name', 120);

            /*
             * The adapter class to use. A string rather than a class name column with a
             * foreign key to nothing: the set of adapters is code, and an operator
             * choosing one that does not exist is a configuration error the gateway
             * reports rather than a row a database can validate.
             */
            $table->string('driver', 40);

            $table->string('base_url', 255)->nullable();
            $table->boolean('is_active')->default(true);

            /*
             * Per-provider knobs that are genuinely provider-specific — an API version
             * header, a region. Deliberately not credentials.
             */
            $table->jsonb('config')->nullable();

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_providers
            ADD CONSTRAINT ai_providers_driver_check
            CHECK (driver IN ('fake', 'openai', 'google', 'anthropic'))
        SQL);

        /*
         * Credentials, separate from the provider and encrypted at rest.
         *
         * A separate table because a provider can have several — a production key and
         * a staging one, or a key being rotated — and because a row nobody needs to
         * read to render an admin screen is a row that never has to be loaded.
         */
        Schema::create('ai_provider_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('provider_id');
            $table->foreign('provider_id')->references('id')->on('ai_providers')->cascadeOnDelete();

            $table->string('label', 120);

            // Encrypted by the application, never readable from a database dump alone.
            $table->text('secret_encrypted');

            // Last four characters, so two keys can be told apart on screen.
            $table->string('secret_hint', 8);

            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            $table->index(['provider_id', 'is_active']);
        });

        // One active credential per provider: two would make "which key did that call
        // use" unanswerable, which is exactly the question a leaked key produces.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_provider_credentials_one_active
            ON ai_provider_credentials (provider_id)
            WHERE is_active
        SQL);

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('provider_id');
            $table->foreign('provider_id')->references('id')->on('ai_providers')->cascadeOnDelete();

            // The provider's own identifier, exactly as their API expects it.
            $table->string('code', 120);
            $table->string('name', 160);

            $table->string('modality', 20);

            $table->unsignedInteger('context_tokens')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();

            /*
             * Whether the provider can be *made* to return valid JSON matching a schema,
             * rather than merely asked nicely. It changes how the gateway prompts and
             * how hard it has to validate.
             */
            $table->boolean('supports_structured_output')->default(false);
            $table->boolean('supports_image_input')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestampTz('deprecated_at')->nullable();

            $table->timestampsTz();

            $table->unique(['provider_id', 'code']);
            $table->index(['modality', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_models
            ADD CONSTRAINT ai_models_modality_check
            CHECK (modality IN ('text', 'vision', 'image', 'embedding'))
        SQL);

        /*
         * What a model costs, over time.
         *
         * Rates change and old jobs must keep reporting what they actually cost, so a
         * rate is effective from a date rather than being a column on the model. In
         * **micros** — millionths of one currency unit — because a thousand tokens can
         * cost less than a cent and storing that in cents makes every report zero.
         */
        Schema::create('ai_cost_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('model_id');
            $table->foreign('model_id')->references('id')->on('ai_models')->cascadeOnDelete();

            $table->string('currency', 3)->default('USD');

            $table->unsignedBigInteger('input_micros_per_million_tokens')->default(0);
            $table->unsignedBigInteger('output_micros_per_million_tokens')->default(0);
            $table->unsignedBigInteger('micros_per_image')->default(0);
            $table->unsignedBigInteger('micros_per_request')->default(0);

            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();

            $table->timestampsTz();

            $table->index(['model_id', 'effective_from']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_cost_rates
            ADD CONSTRAINT ai_cost_rates_window_check
            CHECK (effective_to IS NULL OR effective_to > effective_from)
        SQL);

        /*
         * A prompt, and the versions of it that have existed.
         *
         * Versioned because a prompt is the single largest lever on output quality, and
         * "we changed the wording last Tuesday" has to be a fact somebody can look up
         * against a specific job rather than a memory. A version, once published, is
         * never edited — editing one would silently rewrite the history of every job
         * that used it.
         */
        Schema::create('prompt_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->string('task', 40);
            $table->text('description')->nullable();

            $table->timestampsTz();

            $table->index('task');
        });

        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('template_id');
            $table->foreign('template_id')->references('id')->on('prompt_templates')->cascadeOnDelete();

            $table->unsignedInteger('version');

            $table->text('system_prompt')->nullable();
            $table->text('user_template');

            // The shape the answer must take, for tasks whose output is parsed.
            $table->jsonb('response_schema')->nullable();

            // Defaults the route can override; a creative render and a data extraction
            // want very different temperatures.
            $table->unsignedSmallInteger('temperature_bps')->default(7000);

            $table->string('status', 20)->default('draft');
            $table->text('change_note')->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['template_id', 'version']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prompt_versions
            ADD CONSTRAINT prompt_versions_status_check
            CHECK (status IN ('draft', 'published', 'retired'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE prompt_versions
            ADD CONSTRAINT prompt_versions_published_check
            CHECK (status <> 'published' OR published_at IS NOT NULL)
        SQL);

        /*
         * A published version is immutable.
         *
         * Enforced by a trigger rather than by convention: the whole value of versioning
         * a prompt is that job 84,102 can be shown the exact text it ran against, and
         * one UPDATE would destroy that for every job that ever used it. Retiring is
         * allowed — that changes which version is chosen next, not what an old one said.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_prompt_versions_immutable()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'published' AND (
                    NEW.system_prompt IS DISTINCT FROM OLD.system_prompt
                    OR NEW.user_template IS DISTINCT FROM OLD.user_template
                    OR NEW.response_schema IS DISTINCT FROM OLD.response_schema
                    OR NEW.temperature_bps IS DISTINCT FROM OLD.temperature_bps
                ) THEN
                    RAISE EXCEPTION 'a published prompt version cannot be edited; create a new version';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prompt_versions_no_edit_after_publish
            BEFORE UPDATE ON prompt_versions
            FOR EACH ROW EXECUTE FUNCTION refconcept_prompt_versions_immutable();
        SQL);

        /*
         * Which model serves which task.
         *
         * The table the whole domain exists for. One active route per task; everything
         * about how that task behaves — provider, model, prompt, timeout, retries, cost
         * ceiling, concurrency — lives here so it can be changed without a deploy.
         */
        Schema::create('ai_task_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('task', 40);

            $table->uuid('primary_model_id');
            $table->foreign('primary_model_id')->references('id')->on('ai_models')->cascadeOnDelete();

            $table->uuid('fallback_model_id')->nullable();
            $table->foreign('fallback_model_id')->references('id')->on('ai_models')->nullOnDelete();

            $table->uuid('prompt_version_id')->nullable();
            $table->foreign('prompt_version_id')->references('id')->on('prompt_versions')->nullOnDelete();

            $table->unsignedSmallInteger('timeout_seconds')->default(60);
            $table->unsignedSmallInteger('max_attempts')->default(3);

            // What the customer is charged, in RefConcept credits.
            $table->unsignedInteger('credit_cost')->default(1);

            /*
             * What RefConcept is willing to spend on one job, in micros. A model priced
             * per token has no natural upper bound on a long input, and a runaway
             * prompt is how a month's AI budget disappears in an afternoon.
             */
            $table->unsignedBigInteger('max_cost_micros')->default(500_000);

            $table->unsignedSmallInteger('max_concurrency')->default(10);

            $table->boolean('is_active')->default(true);

            /*
             * The kill switch. Per route rather than global, because "image rendering
             * is broken" should not stop the support assistant, and an operator under
             * pressure should be able to turn off exactly the thing that is on fire.
             */
            $table->boolean('is_paused')->default(false);
            $table->string('pause_reason', 300)->nullable();

            $table->timestampsTz();

            $table->index(['task', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_task_routes
            ADD CONSTRAINT ai_task_routes_pause_check
            CHECK (NOT is_paused OR pause_reason IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_task_routes
            ADD CONSTRAINT ai_task_routes_fallback_differs_check
            CHECK (fallback_model_id IS NULL OR fallback_model_id <> primary_model_id)
        SQL);

        // One active route per task: two would make "which model runs this" a race.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_task_routes_one_active_per_task
            ON ai_task_routes (task)
            WHERE is_active
        SQL);

        /*
         * One unit of work.
         *
         * The subject is polymorphic on purpose: a job renders a design version, tags a
         * product, or rewrites a search query, and a foreign key per possibility would
         * be a column per feature.
         */
        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('task', 40);
            $table->string('status', 20)->default('queued');

            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();

            // Who is waiting for it, so per-user concurrency can be enforced and a
            // failure can be explained to the right person.
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->uuid('route_id')->nullable();
            $table->foreign('route_id')->references('id')->on('ai_task_routes')->nullOnDelete();

            $table->jsonb('input');
            $table->jsonb('output')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('credit_cost')->default(0);

            // Denormalised from ai_usage so a list of jobs does not need a join.
            $table->unsignedBigInteger('total_cost_micros')->default(0);
            $table->unsignedInteger('total_latency_ms')->default(0);

            $table->string('failure_kind', 40)->nullable();
            $table->text('failure_reason')->nullable();

            // For deduplication: the same request arriving twice is one job.
            $table->string('idempotency_key', 120)->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_status_check
            CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_terminal_check
            CHECK (
                (status <> 'succeeded' OR (finished_at IS NOT NULL AND output IS NOT NULL))
                AND (status <> 'failed' OR (finished_at IS NOT NULL AND failure_kind IS NOT NULL))
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_jobs_idempotency
            ON ai_jobs (idempotency_key)
            WHERE idempotency_key IS NOT NULL
        SQL);

        /*
         * One attempt against one provider.
         *
         * A job can have several: a retry, then a fallback to another provider. Keeping
         * them apart is what makes "the primary is timing out but the fallback is fine"
         * visible rather than a vague sense that things are slow.
         */
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('job_id');
            $table->foreign('job_id')->references('id')->on('ai_jobs')->cascadeOnDelete();

            $table->unsignedSmallInteger('attempt');

            $table->uuid('provider_id')->nullable();
            $table->foreign('provider_id')->references('id')->on('ai_providers')->nullOnDelete();

            $table->uuid('model_id')->nullable();
            $table->foreign('model_id')->references('id')->on('ai_models')->nullOnDelete();

            $table->uuid('prompt_version_id')->nullable();
            $table->foreign('prompt_version_id')->references('id')->on('prompt_versions')->nullOnDelete();

            $table->boolean('is_fallback')->default(false);

            /*
             * The rendered prompt, so "why did it answer that" is answerable against the
             * exact text used. A customer's room photograph is *not* stored here — only
             * a reference to it — because this table is read by staff.
             */
            $table->text('rendered_prompt')->nullable();

            $table->string('status', 20);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);

            $table->timestampTz('created_at');

            $table->index(['job_id', 'attempt']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_requests
            ADD CONSTRAINT ai_requests_status_check
            CHECK (status IN ('succeeded', 'failed'))
        SQL);

        /*
         * What one attempt consumed and what it cost.
         *
         * Separate from the request because it is what every report aggregates, and a
         * table of numbers is far cheaper to scan than one carrying prompt text.
         */
        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('request_id');
            $table->foreign('request_id')->references('id')->on('ai_requests')->cascadeOnDelete();

            $table->uuid('job_id');
            $table->foreign('job_id')->references('id')->on('ai_jobs')->cascadeOnDelete();

            $table->uuid('model_id')->nullable();
            $table->foreign('model_id')->references('id')->on('ai_models')->nullOnDelete();

            $table->string('task', 40);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('image_count')->default(0);

            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->string('currency', 3)->default('USD');

            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            $table->timestampTz('created_at');

            $table->index(['task', 'created_at']);
            $table->index(['model_id', 'created_at']);
        });

        Schema::create('ai_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('job_id');
            $table->foreign('job_id')->references('id')->on('ai_jobs')->cascadeOnDelete();

            $table->uuid('request_id')->nullable();
            $table->foreign('request_id')->references('id')->on('ai_requests')->nullOnDelete();

            $table->string('kind', 40);
            $table->text('message');
            $table->boolean('was_retryable');
            $table->unsignedSmallInteger('attempt');

            $table->timestampTz('created_at');

            $table->index(['kind', 'created_at']);
            $table->index(['job_id', 'attempt']);
        });
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prompt_versions_no_edit_after_publish ON prompt_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_prompt_versions_immutable()');

        Schema::dropIfExists('ai_failures');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('ai_task_routes');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompt_templates');
        Schema::dropIfExists('ai_cost_rates');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_provider_credentials');
        Schema::dropIfExists('ai_providers');
    }
};

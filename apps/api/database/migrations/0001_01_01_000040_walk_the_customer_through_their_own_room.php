<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lets a finished design move.
 *
 * A render is a photograph of a room that does not exist yet, and a photograph is read in
 * a second. What it cannot show is the thing people actually buy furniture for: how the
 * room feels when you walk into it. Eight seconds of a camera easing forward past the sofa
 * does that, and it does it with real parallax — the coffee table passes in front of the
 * rug, the far wall stays put — which is the difference between a picture of a room and a
 * room.
 *
 * Priced honestly and charged for. Veo 3.1 Lite at 1080p is eight cents a second, so one
 * film is about sixty-four cents — roughly three premium renders — and it is a button the
 * customer presses on purpose rather than something that happens to every design. Twenty
 * credits, held before the job starts and released in full if it fails.
 *
 * The render is the first frame, not a description of it. Handed only words, the model
 * composes its own room and the video is a tour of somewhere else — the exact failure that
 * moved the still render onto a different provider. The camera may only reveal what the
 * photograph already showed.
 */
return new class extends Migration
{
    private const TASK = 'video_tour';

    public function up(): void
    {
        /*
         * The state of one film, kept apart from the file it produces.
         *
         * The same split as design_versions and design_assets, for the same reason: the
         * file is what exists at the end, and the customer is watching the two minutes
         * before that. A row here exists from the moment the button is pressed, so
         * "üretiliyor" is a fact somebody can poll rather than an absence to infer.
         */
        Schema::create('design_videos', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('design_version_id');
            $table->foreign('design_version_id')->references('id')->on('design_versions')->cascadeOnDelete();

            $table->string('status', 20)->default('pending');

            // Who pressed it, so a failure can be explained to the right person and a
            // shared project shows whose credits paid.
            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();

            $table->unsignedSmallInteger('duration_seconds')->default(8);

            $table->unsignedInteger('credit_cost')->default(0);

            $table->uuid('credit_reservation_id')->nullable();
            $table->foreign('credit_reservation_id')->references('id')->on('credit_reservations')->nullOnDelete();

            $table->uuid('ai_job_id')->nullable();
            $table->foreign('ai_job_id')->references('id')->on('ai_jobs')->nullOnDelete();

            // The finished file, once there is one.
            $table->uuid('asset_id')->nullable();
            $table->foreign('asset_id')->references('id')->on('design_assets')->nullOnDelete();

            $table->text('failure_reason')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['design_version_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE design_videos
            ADD CONSTRAINT design_videos_status_check
            CHECK (status IN ('pending', 'generating', 'ready', 'failed'))
        SQL);

        /*
         * One film in flight per version.
         *
         * Not one film ever — a customer who did not like the camera move should be able to
         * pay for another — but two running at once is a double charge for one button
         * pressed twice, which is what an impatient click on a slow page produces.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX design_videos_one_in_flight
            ON design_videos (design_version_id)
            WHERE status IN ('pending', 'generating')
        SQL);

        /*
         * A fourth thing a model can be.
         *
         * Its own modality rather than a flavour of image, because everything about the
         * call is different: it is answered by an operation the caller polls for a minute
         * or two, the result is tens of megabytes, and it is priced by the second rather
         * than by the token. A model that draws pictures cannot serve it, and routing must
         * be able to say so before a job is ever accepted.
         */
        DB::statement('ALTER TABLE ai_models DROP CONSTRAINT ai_models_modality_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_models
            ADD CONSTRAINT ai_models_modality_check
            CHECK (modality IN ('text', 'vision', 'image', 'video', 'embedding'))
        SQL);

        // The file itself lives with the renders, on the same private disk and behind the
        // same ownership check. A film of somebody's home is no less private than a still.
        DB::statement('ALTER TABLE design_assets DROP CONSTRAINT design_assets_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE design_assets
            ADD CONSTRAINT design_assets_type_check
            CHECK (type IN ('render', 'thumbnail', 'depth', 'mask', 'overlay', 'video'))
        SQL);

        $this->seedRoute();
    }

    public function down(): void
    {
        DB::table('ai_task_routes')->where('task', self::TASK)->delete();

        Schema::dropIfExists('design_videos');

        /*
         * Any video assets go with the routing, because the type is about to become
         * illegal and a constraint that cannot be added is a migration that cannot be
         * rolled back. Soft-deleted rather than removed: the file stays on disk and the
         * row stays readable, which is the same promise every other deletion here makes.
         */
        DB::table('design_assets')->where('type', 'video')->update(['deleted_at' => now()]);
        DB::table('design_assets')->where('type', 'video')->update(['type' => 'overlay']);

        DB::statement('ALTER TABLE design_assets DROP CONSTRAINT design_assets_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE design_assets
            ADD CONSTRAINT design_assets_type_check
            CHECK (type IN ('render', 'thumbnail', 'depth', 'mask', 'overlay'))
        SQL);

        DB::table('ai_models')->where('modality', 'video')->delete();

        DB::statement('ALTER TABLE ai_models DROP CONSTRAINT ai_models_modality_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_models
            ADD CONSTRAINT ai_models_modality_check
            CHECK (modality IN ('text', 'vision', 'image', 'embedding'))
        SQL);
    }

    /**
     * The model that films the room, what it costs, and what it is told.
     */
    private function seedRoute(): void
    {
        $provider = DB::table('ai_providers')->where('code', 'google')->value('id');

        if ($provider === null) {
            return;
        }

        $model = $this->model((string) $provider);
        $version = $this->prompt();

        if (DB::table('ai_task_routes')->where('task', self::TASK)->exists()) {
            return;
        }

        DB::table('ai_task_routes')->insert([
            'id' => (string) Str::uuid7(),
            'task' => self::TASK,
            'primary_model_id' => $model,

            /*
             * No fallback. Nothing else on the platform can film a room, and pointing this
             * at an image model would answer a request for a video with a picture — a
             * failure that looks like success all the way to the customer's screen.
             */
            'fallback_model_id' => null,
            'prompt_version_id' => $version,

            // Measured: a 1080p eight-second film took a hundred and ten seconds end to
            // end, including the download. Ten minutes is the ceiling before the platform
            // decides the operation is never coming back.
            'timeout_seconds' => 600,

            /*
             * Two attempts, not three. Each one is a real sixty-four cents, and the
             * gateway only retries what it classifies as transient — a refusal or a
             * malformed answer is not tried again at that price.
             */
            'max_attempts' => 2,

            // About three premium renders, which is roughly what it costs us.
            'credit_cost' => 20,

            // Sixty-four cents is the expected bill; the cap leaves room for a retry and
            // stops a mispriced model from quietly costing ten times that.
            'max_cost_micros' => 1_400_000,

            /*
             * Two at a time across the whole platform. Each one holds a worker for two
             * minutes, and the AI queue also carries every render — a rush of videos must
             * not be able to stall the thing customers press far more often.
             */
            'max_concurrency' => 2,

            'is_active' => true,
            'is_paused' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function model(string $providerId): string
    {
        $existing = DB::table('ai_models')
            ->where('provider_id', $providerId)
            ->where('code', 'veo-3.1-lite-generate-preview')
            ->value('id');

        if ($existing !== null) {
            $this->rate((string) $existing);

            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('ai_models')->insert([
            'id' => $id,
            'provider_id' => $providerId,
            /*
             * The lite model, verified against the live API rather than chosen from a
             * price list. It produced an eight-second 1080p film from a render in a
             * hundred and ten seconds, with the room intact and real parallax. The full
             * Veo 3.1 is five times the price for a difference nobody watching an eight
             * second tour of their own living room would be able to name.
             */
            'code' => 'veo-3.1-lite-generate-preview',
            'name' => 'Veo 3.1 Lite',
            'modality' => 'video',
            'supports_structured_output' => false,
            'supports_image_input' => true,
            'max_output_tokens' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rate($id);

        return $id;
    }

    /**
     * Eight cents a second at 1080p, recorded per request because that is how it is sold.
     *
     * Video is the one thing here not priced by the token. Forcing it into the token
     * columns would make every cost report about it wrong in a way nobody would catch,
     * so it goes in `micros_per_request` and the number means one eight-second film.
     */
    private function rate(string $modelId): void
    {
        $exists = DB::table('ai_cost_rates')
            ->where('model_id', $modelId)
            ->whereNull('effective_to')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('ai_cost_rates')->insert([
            'id' => (string) Str::uuid7(),
            'model_id' => $modelId,
            'currency' => 'USD',
            'input_micros_per_million_tokens' => 0,
            'output_micros_per_million_tokens' => 0,
            'micros_per_image' => 0,
            'micros_per_request' => 640_000,
            'effective_from' => now(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * What the camera is told.
     *
     * In English, alone among the prompts here, and not by accident: this is the wording
     * that was tested against the live model and produced a film of the right room. Video
     * models are trained overwhelmingly on English shot descriptions, and the terms that
     * carry meaning to them — dolly, arc, parallax — have no equally precise Turkish
     * equivalent that the model has ever been taught. The customer never reads it.
     *
     * Every sentence after the first is a fence. Left to itself the model redecorates:
     * it swaps a sofa, opens a door that was shut, walks the camera into a room that was
     * never in the photograph. The render is the only room there is.
     */
    private function prompt(): string
    {
        $template = DB::table('prompt_templates')->where('code', self::TASK)->value('id');

        if ($template === null) {
            $template = (string) Str::uuid7();

            DB::table('prompt_templates')->insert([
                'id' => $template,
                'code' => self::TASK,
                'name' => 'Oda videosu',
                'task' => self::TASK,
                'description' => 'Bitmiş tasarımdan sekiz saniyelik oda turu üretir.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('prompt_versions')
            ->where('template_id', $template)
            ->where('version', 1)
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid7();

        DB::table('prompt_versions')->insert([
            'id' => $id,
            'template_id' => $template,
            'version' => 1,
            'status' => 'published',
            'published_at' => now(),
            // Nothing creative is wanted here. The camera move is the only variable and it
            // is written down; everything else is a change to a room somebody designed.
            'temperature_bps' => 2_000,
            /*
             * Left empty on purpose. The video endpoint takes a single prompt string and
             * has nowhere to put a system message, so a system prompt here would be a
             * paragraph of instructions that silently never reached the model.
             */
            'system_prompt' => null,
            'user_template' => implode(' ', [
                'Slow, smooth cinematic camera move through this exact interior.',
                'The room, its walls, windows, doors, floor and every piece of furniture stay',
                'exactly as they are — do not change, add or remove anything, and do not walk',
                'the camera into a space the still image does not show.',
                '{{ camera_move }}',
                'Natural daylight from the room own windows, consistent shadows, no cuts,',
                'no people, no text, no captions, no music.',
                'Style reference: {{ style }}. Room: {{ room_type }}.',
            ]),
            'response_schema' => null,
            'change_note' => 'İlk sürüm: sabit oda, yalnızca kamera hareket eder.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};

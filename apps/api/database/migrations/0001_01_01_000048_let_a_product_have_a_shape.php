<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a 3D model beside a product's photographs, and a note of where it came from.
 *
 * `product_media` has allowed `model_3d` since the catalogue was built; nothing ever wrote
 * one, because nobody had a model to write. Now two things can produce one — a seller with a
 * file from their manufacturer, and a mesh generated from their own photograph — and telling
 * them apart matters in three places.
 *
 * The planner labels a generated mesh, because its back is a guess and a customer looking at
 * the back of a sofa should know that. A seller's own file always wins over a generated one,
 * which needs a rule that can see the difference. And an operator who rejects a bad mesh must
 * be able to remove the generated one without touching anything the seller uploaded.
 *
 * One of each per product, enforced rather than hoped for: a second generated mesh is a
 * second answer to "what does this look like", and whichever the query happened to return
 * first would be the one the customer saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table): void {
            $table->string('source', 10)->default('seller');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_media
            ADD CONSTRAINT product_media_source_check
            CHECK (source IN ('seller', 'ai'))
        SQL);

        // Photographs are many and unconstrained; a model is one per source per product.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX product_media_single_model
            ON product_media (product_id, source)
            WHERE type = 'model_3d' AND deleted_at IS NULL
        SQL);

        /*
         * And the provider that makes them.
         *
         * The driver list is a CHECK rather than a lookup table, which is right — an adapter
         * either exists in this build or it does not, and a row naming one that does not is a
         * job that fails at run time. Adding a driver therefore means widening the check, in
         * the same migration as the feature that needs it.
         */
        DB::statement('ALTER TABLE ai_providers DROP CONSTRAINT ai_providers_driver_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_providers
            ADD CONSTRAINT ai_providers_driver_check
            CHECK (driver IN ('fake', 'openai', 'google', 'anthropic', 'fal'))
        SQL);

        DB::statement('ALTER TABLE ai_models DROP CONSTRAINT ai_models_modality_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_models
            ADD CONSTRAINT ai_models_modality_check
            CHECK (modality IN ('text', 'vision', 'image', 'video', 'model_3d', 'embedding'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_media_single_model');
        DB::statement('ALTER TABLE product_media DROP CONSTRAINT IF EXISTS product_media_source_check');

        Schema::table('product_media', function (Blueprint $table): void {
            $table->dropColumn('source');
        });

        DB::statement('ALTER TABLE ai_models DROP CONSTRAINT ai_models_modality_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_models
            ADD CONSTRAINT ai_models_modality_check
            CHECK (modality IN ('text', 'vision', 'image', 'video', 'embedding'))
        SQL);

        DB::statement('ALTER TABLE ai_providers DROP CONSTRAINT ai_providers_driver_check');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_providers
            ADD CONSTRAINT ai_providers_driver_check
            CHECK (driver IN ('fake', 'openai', 'google', 'anthropic'))
        SQL);
    }
};

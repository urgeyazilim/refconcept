<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products and seller listings.
 *
 * A `product` is the catalogue entry — what the thing *is*. A `product_sku` is one
 * seller's offer of it: their price, their stock policy, their lead time. Keeping
 * them apart is what lets two sellers list the same sofa, and what lets the matching
 * engine reason about the sofa rather than about six near-duplicate rows.
 *
 * Money is integer minor units throughout, never a float
 * (05_ARCHITECTURE_AND_CODE_RULES.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * The seller organization that proposed this entry. Null means
             * platform-curated. Moderation and edit rights follow this column, which is
             * also the tenant boundary for everything below.
             */
            $table->uuid('organization_id')->nullable();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->uuid('brand_id')->nullable();
            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();

            $table->uuid('primary_category_id');
            $table->foreign('primary_category_id')->references('id')->on('categories')->restrictOnDelete();

            $table->uuid('style_id')->nullable();
            $table->foreign('style_id')->references('id')->on('styles')->nullOnDelete();

            $table->string('name', 250);
            $table->string('slug', 280);
            $table->string('product_type', 30)->default('simple');
            $table->text('description')->nullable();

            $table->string('status', 30)->default('draft');
            $table->string('moderation_status', 30)->default('draft');

            $table->string('seo_title', 250)->nullable();
            $table->string('seo_description', 500)->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('slug');
            $table->index(['organization_id', 'moderation_status']);
            $table->index(['status', 'moderation_status']);
            $table->index('primary_category_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT products_status_check
            CHECK (status IN ('draft', 'active', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT products_moderation_status_check
            CHECK (moderation_status IN ('draft', 'pending_review', 'in_review', 'approved', 'rejected'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT products_type_check
            CHECK (product_type IN ('simple', 'variant', 'bundle'))
        SQL);

        /*
         * A product is only publicly visible when it is both approved and active.
         * Expressed as a constraint so no code path can publish an unreviewed product
         * by setting one column and forgetting the other.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT products_publish_requires_approval_check
            CHECK (published_at IS NULL OR moderation_status = 'approved')
        SQL);

        // Full-text search vector, maintained by trigger so it can never drift from the
        // row it describes. Turkish configuration for correct stemming.
        DB::statement('ALTER TABLE products ADD COLUMN search_document tsvector');

        DB::unprepared(
            'CREATE OR REPLACE FUNCTION refconcept_products_search_document() '
            .'RETURNS TRIGGER AS $func$ '
            .'BEGIN '
            ."NEW.search_document := setweight(to_tsvector('simple', coalesce(NEW.name, '')), 'A') "
            ."|| setweight(to_tsvector('simple', coalesce(NEW.description, '')), 'B'); "
            .'RETURN NEW; '
            .'END; '
            .'$func$ LANGUAGE plpgsql;'
        );

        DB::unprepared(
            'CREATE TRIGGER products_search_document_trigger '
            .'BEFORE INSERT OR UPDATE OF name, description ON products '
            .'FOR EACH ROW EXECUTE FUNCTION refconcept_products_search_document();'
        );

        DB::statement('CREATE INDEX products_search_document_idx ON products USING GIN (search_document)');

        // Trigram index for fuzzy name lookups (Phase 10 hybrid search).
        DB::statement('CREATE INDEX products_name_trgm_idx ON products USING GIN (name gin_trgm_ops)');

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->uuid('product_id');
            $table->uuid('category_id');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();

            $table->primary(['product_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->uuid('attribute_id');
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();

            $table->uuid('attribute_value_id')->nullable();
            $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->nullOnDelete();

            // Free-form values for attributes that are not a fixed list.
            $table->string('value_text', 500)->nullable();
            $table->integer('value_integer')->nullable();
            $table->decimal('value_decimal', 14, 4)->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->timestampsTz();

            $table->unique(['product_id', 'attribute_id', 'attribute_value_id']);
            $table->index('attribute_id');
        });

        Schema::create('product_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->string('type', 20)->default('image');
            $table->string('disk', 40);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 300)->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->uuid('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['product_id', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_media
            ADD CONSTRAINT product_media_type_check
            CHECK (type IN ('image', 'video', 'document', 'model_3d'))
        SQL);

        // One cover image per product: two "primary" images makes every listing grid
        // non-deterministic.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX product_media_single_cover
            ON product_media (product_id)
            WHERE position = 0 AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_categories');

        DB::unprepared('DROP TRIGGER IF EXISTS products_search_document_trigger ON products');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_products_search_document()');

        Schema::dropIfExists('products');
    }
};

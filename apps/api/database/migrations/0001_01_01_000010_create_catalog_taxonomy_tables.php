<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog taxonomy: the shared vocabulary every product is described with.
 *
 * This is platform-owned, not seller-owned. If each seller invented their own
 * categories and colours, the Phase 9 matching engine would have nothing to match on —
 * "bej" from one seller and "beige" from another would be unrelated strings, and a
 * budget filter across sellers would be meaningless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('parent_id')->nullable();

            $table->string('name', 160);
            $table->string('slug', 180);

            /*
             * Materialised path ("mobilya/oturma-grubu/kanepe") and depth.
             *
             * A tree walked by parent_id alone needs one query per level; the path makes
             * "everything under Mobilya" a single prefix scan, which is what category
             * pages and the matching engine actually ask for.
             */
            $table->string('path', 800);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('position')->default(0);

            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);

            // Which room this category belongs in, so an AI design for a bedroom does not
            // propose kitchen cabinetry.
            $table->string('room_type', 40)->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique('slug');
            $table->index('path');
            $table->index(['parent_id', 'position']);
            $table->index('room_type');
        });

        // The self-reference is added after creation: Postgres needs the primary key to
        // exist before a foreign key can point at it, and Laravel emits both in the same
        // statement batch when they are declared inside the create closure.
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->cascadeOnDelete();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('description', 1000)->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('attributes', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->string('data_type', 20);
            $table->string('unit', 20)->nullable();

            /*
             * A variant-defining attribute is one where a different value means a
             * different SKU (colour, size). A merely filterable one does not (care
             * instructions). Confusing the two produces either duplicate SKUs or
             * un-buyable variants.
             */
            $table->boolean('is_variant_defining')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            $table->index('is_filterable');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE attributes
            ADD CONSTRAINT attributes_data_type_check
            CHECK (data_type IN ('string', 'integer', 'decimal', 'boolean', 'select', 'multiselect'))
        SQL);

        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('attribute_id');
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();

            $table->string('value', 160);
            $table->string('label', 160);
            $table->unsignedInteger('position')->default(0);

            $table->timestampsTz();

            $table->unique(['attribute_id', 'value']);
        });

        Schema::create('category_attributes', function (Blueprint $table): void {
            $table->uuid('category_id');
            $table->uuid('attribute_id');

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();

            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->primary(['category_id', 'attribute_id']);
        });

        /*
         * Colours, materials and styles are first-class rather than free text.
         *
         * The AI extracts "warm oak" from a design and has to find products made of oak;
         * that only works if oak is one row every seller points at, not a string each
         * seller spells differently.
         */
        Schema::create('colors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->char('hex', 7)->nullable();
            $table->string('family', 60)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->index('family');
        });

        Schema::create('materials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('family', 60)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('styles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('styles');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('colors');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};

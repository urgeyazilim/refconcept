<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller listings, physical properties and moderation.
 *
 * `product_skus` is where a seller's commercial terms live. Every monetary column is
 * a bigint of minor units with an explicit currency; there is no float anywhere near
 * a price, and no percentage stored as a decimal — tax is basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_skus', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('sku', 80);
            $table->string('barcode', 60)->nullable();
            $table->string('variant_label', 160)->nullable();

            $table->string('status', 30)->default('draft');

            $table->char('currency', 3)->default('TRY');

            /*
             * Minor units as integers: 48.900,00 ₺ is 4890000, not 48900.0. A float here
             * would turn a marketplace total into an approximation, and the ledger in
             * Phase 16 balances to the kuruş.
             */
            $table->bigInteger('list_price_minor');
            $table->bigInteger('sale_price_minor')->nullable();

            // Basis points, so 20% is 2000 and the arithmetic stays exact.
            $table->integer('tax_rate_bps')->default(2000);

            $table->string('stock_policy', 30)->default('track');
            $table->integer('stock_quantity')->default(0);
            $table->unsignedSmallInteger('lead_time_days')->default(3);

            $table->timestampsTz();
            $table->softDeletesTz();

            // A seller's own SKU code must be unique within that seller, not globally:
            // two sellers can legitimately both use "SOFA-01".
            $table->unique(['seller_id', 'sku']);
            $table->index(['product_id', 'status']);
            $table->index('seller_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_skus
            ADD CONSTRAINT product_skus_status_check
            CHECK (status IN ('draft', 'active', 'paused', 'out_of_stock', 'archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE product_skus
            ADD CONSTRAINT product_skus_stock_policy_check
            CHECK (stock_policy IN ('track', 'always_available', 'made_to_order'))
        SQL);

        // Prices are never negative, and a sale price above the list price is a data
        // entry error that would otherwise show as a negative discount to the customer.
        DB::statement(<<<'SQL'
            ALTER TABLE product_skus
            ADD CONSTRAINT product_skus_price_check
            CHECK (list_price_minor >= 0 AND (sale_price_minor IS NULL OR (sale_price_minor >= 0 AND sale_price_minor <= list_price_minor)))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE product_skus
            ADD CONSTRAINT product_skus_tax_rate_check
            CHECK (tax_rate_bps >= 0 AND tax_rate_bps <= 10000)
        SQL);

        // Tracked stock cannot be negative; the other policies do not track a number.
        DB::statement(<<<'SQL'
            ALTER TABLE product_skus
            ADD CONSTRAINT product_skus_stock_check
            CHECK (stock_policy <> 'track' OR stock_quantity >= 0)
        SQL);

        /**
         * Physical properties, on the SKU rather than the product: a two-seat and a
         * three-seat sofa are the same product and different dimensions, and the AI
         * placing furniture in a room needs the dimensions of the exact thing bought.
         */
        Schema::create('product_dimensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            // Millimetres and grams as integers: the units the AI reasons in, and no
            // rounding drift when converting for display.
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->unsignedInteger('depth_mm')->nullable();
            $table->unsignedInteger('weight_g')->nullable();

            $table->unsignedInteger('package_count')->default(1);
            $table->boolean('assembly_required')->default(false);

            $table->timestampsTz();

            $table->unique('sku_id');
        });

        Schema::create('product_variant_values', function (Blueprint $table): void {
            $table->uuid('sku_id');
            $table->uuid('attribute_id');
            $table->uuid('attribute_value_id');

            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();
            $table->foreign('attribute_value_id')->references('id')->on('attribute_values')->cascadeOnDelete();

            $table->primary(['sku_id', 'attribute_id']);
            $table->index('attribute_value_id');
        });

        Schema::create('product_moderation', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->string('decision', 30);
            $table->string('reason', 1000);

            // Which field the reviewer objected to, so the seller can fix the right thing
            // instead of guessing from a paragraph of prose.
            $table->jsonb('flagged_fields')->nullable();

            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('decided_at')->useCurrent();

            $table->index(['product_id', 'decided_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE product_moderation
            ADD CONSTRAINT product_moderation_decision_check
            CHECK (decision IN ('approved', 'rejected', 'changes_requested'))
        SQL);

        Schema::create('product_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->string('field', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('reason', 1000)->nullable();

            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('changed_at')->useCurrent();

            $table->index(['product_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_status_history');
        Schema::dropIfExists('product_moderation');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_dimensions');
        Schema::dropIfExists('product_skus');
    }
};

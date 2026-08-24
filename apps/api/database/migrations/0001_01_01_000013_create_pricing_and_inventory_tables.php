<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing and inventory.
 *
 * Two aggregates that look simple and are not. Prices need history because a customer
 * who bought yesterday bought yesterday's price, and a seller who mistypes a discount
 * needs somebody to be able to say what it was before. Stock needs to be a ledger for
 * the same reason a bank balance is: a single mutable number cannot answer "where did
 * the other four go", and two concurrent orders will happily read it at the same time
 * and both decide there is one left.
 *
 * So `stock_items` holds the aggregate for reads, and `stock_movements` is the
 * authoritative record it is derived from. Every write goes through a locked
 * transaction — see the InventoryLedger service — and a CHECK constraint refuses a
 * negative or over-reserved balance even if one ever slipped past the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A named set of prices a seller can apply to their own SKUs.
         *
         * Every seller has a default list. Additional lists are how a campaign or a
         * B2B tier is expressed without overwriting the everyday price — which is what
         * makes "restore the old price when the campaign ends" possible at all.
         */
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('currency', 3)->default('TRY');

            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');

            // A campaign list is bounded in time; the default list is not.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['seller_id', 'code']);
            $table->index(['seller_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE price_lists
            ADD CONSTRAINT price_lists_status_check
            CHECK (status IN ('draft', 'active', 'ended'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE price_lists
            ADD CONSTRAINT price_lists_window_check
            CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
        SQL);

        // One default list per seller: two would make "which price applies" ambiguous.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX price_lists_single_default
            ON price_lists (seller_id)
            WHERE is_default AND deleted_at IS NULL
        SQL);

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('price_list_id');
            $table->foreign('price_list_id')->references('id')->on('price_lists')->cascadeOnDelete();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            // Minor units, like every amount in the system.
            $table->bigInteger('list_price_minor');
            $table->bigInteger('sale_price_minor')->nullable();
            $table->string('currency', 3)->default('TRY');

            $table->timestampsTz();

            $table->unique(['price_list_id', 'sku_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE price_list_items
            ADD CONSTRAINT price_list_items_amounts_check
            CHECK (
                list_price_minor >= 0
                AND (sale_price_minor IS NULL OR (sale_price_minor >= 0 AND sale_price_minor <= list_price_minor))
            )
        SQL);

        /*
         * Every price change, forever.
         *
         * Append-only: there is no path in the application that updates or deletes a
         * row here, and a trigger enforces it. A price is the single most disputed
         * number in a marketplace, and a history somebody can edit answers nothing.
         */
        Schema::create('price_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            $table->uuid('price_list_id')->nullable();
            $table->foreign('price_list_id')->references('id')->on('price_lists')->nullOnDelete();

            $table->string('field', 30);
            $table->bigInteger('old_value_minor')->nullable();
            $table->bigInteger('new_value_minor')->nullable();
            $table->string('currency', 3)->default('TRY');

            // Where the change came from: a form, a spreadsheet import, an API call.
            $table->string('source', 30)->default('manual');
            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('changed_at');

            $table->index(['sku_id', 'changed_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE price_history
            ADD CONSTRAINT price_history_field_check
            CHECK (field IN ('list_price', 'sale_price'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE price_history
            ADD CONSTRAINT price_history_source_check
            CHECK (source IN ('manual', 'import', 'api', 'campaign', 'system'))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_price_history_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'price_history is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER price_history_no_update
            BEFORE UPDATE OR DELETE ON price_history
            FOR EACH ROW EXECUTE FUNCTION refconcept_price_history_immutable();
        SQL);

        /*
         * Where a seller's stock physically sits.
         *
         * Stock is per location rather than per SKU because "we have twelve" is not
         * useful when nine are in a warehouse that does not ship to the customer's
         * city. Every seller gets a default location so the simple case stays simple.
         */
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 160);
            $table->string('type', 20)->default('warehouse');

            $table->string('city', 120)->nullable();
            $table->string('country_code', 2)->default('TR');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['seller_id', 'code']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_locations
            ADD CONSTRAINT stock_locations_type_check
            CHECK (type IN ('warehouse', 'store', 'dropship', 'supplier'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_locations_single_default
            ON stock_locations (seller_id)
            WHERE is_default AND deleted_at IS NULL
        SQL);

        /*
         * The balance, derived from the movements below.
         *
         * `on_hand` is what physically exists; `reserved` is what is spoken for but
         * not yet dispatched. Sellable is the difference, and the CHECK constraints
         * are the last line of defence: reserving more than exists is the bug that
         * oversells, and it must be impossible at the storage layer, not merely
         * unlikely at the service layer.
         */
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('stock_locations')->cascadeOnDelete();

            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);

            // Below this, the seller is warned. Not enforced — it is a signal, not a rule.
            $table->integer('reorder_point')->default(0);

            $table->timestampTz('counted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['sku_id', 'location_id']);
            $table->index('location_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_items
            ADD CONSTRAINT stock_items_balance_check
            CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)
        SQL);

        /*
         * The authoritative record: what moved, when, why and on whose behalf.
         *
         * Append-only for the same reason as price history. A balance that disagrees
         * with the sum of its movements is a bug you can find; a balance with no
         * movements behind it is one you cannot.
         */
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('stock_item_id');
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();

            $table->string('type', 20);

            // Signed: a receipt is positive, a dispatch negative. The sign is part of
            // the data rather than implied by the type, so a sum is just a sum.
            $table->integer('quantity');

            $table->integer('on_hand_after');
            $table->integer('reserved_after');

            // What caused it — an order, an import, a stocktake.
            $table->string('reference_type', 60)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason', 300)->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('created_at');

            $table->index(['stock_item_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
            ADD CONSTRAINT stock_movements_type_check
            CHECK (type IN ('receipt', 'adjustment', 'stocktake', 'reserve', 'release', 'dispatch', 'return'))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_stock_movements_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_movements_no_update
            BEFORE UPDATE OR DELETE ON stock_movements
            FOR EACH ROW EXECUTE FUNCTION refconcept_stock_movements_immutable();
        SQL);

        /*
         * A hold on stock that has not been paid for yet.
         *
         * Reservations expire. Without that, one abandoned basket removes a sofa from
         * sale forever, and the seller has no way to tell the difference between sold
         * and forgotten.
         */
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('stock_item_id');
            $table->foreign('stock_item_id')->references('id')->on('stock_items')->cascadeOnDelete();

            $table->integer('quantity');
            $table->string('status', 20)->default('held');

            $table->string('reference_type', 60);
            $table->uuid('reference_id');

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_reservations
            ADD CONSTRAINT stock_reservations_status_check
            CHECK (status IN ('held', 'released', 'consumed', 'expired'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE stock_reservations
            ADD CONSTRAINT stock_reservations_quantity_check
            CHECK (quantity > 0)
        SQL);

        // One live hold per reference: retrying a checkout must not double-reserve.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_reservations_one_hold_per_reference
            ON stock_reservations (stock_item_id, reference_type, reference_id)
            WHERE status = 'held'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_stock_movements_immutable()');
        DB::unprepared('DROP TRIGGER IF EXISTS price_history_no_update ON price_history');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_price_history_immutable()');

        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('price_history');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};

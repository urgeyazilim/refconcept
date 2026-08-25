<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things a customer has kept, and things they are about to buy.
 *
 * A cart in a marketplace is not one shop's basket. Six sellers may each be shipping part
 * of it, each with their own stock, their own lead time and eventually their own payout —
 * so the seller is recorded on every line and the grouping is a property of the data
 * rather than a way of drawing it.
 *
 * The line carries a **price snapshot**, and everything interesting about carts follows
 * from that one decision. A price can move between adding something and paying for it, and
 * there are only three honest ways to handle it: refuse the change, silently apply it, or
 * show it. The third is the only one a customer would call fair, and it needs the old
 * number kept — which is what `unit_price_minor` is.
 *
 * Stock is *not* held while something sits in a cart. Holding it would mean a browser tab
 * left open for a week keeps a sofa off the market, and the marketplace's job is to sell
 * the sofa. The hold is taken at checkout, for minutes, by the ledger built in Phase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Something a customer wants to remember.
         *
         * Deliberately per product rather than per offer. A customer favouriting a sofa
         * means the sofa, not one seller's listing of it — and a favourite that broke when
         * that seller went out of stock would be a promise the feature did not make.
         */
        Schema::create('favorites', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->timestampTz('created_at');

            // Favouriting twice is the same as favouriting once. Enforced here so a double
            // tap is a no-op rather than two rows and a count that reads wrong.
            $table->unique(['user_id', 'product_id']);
            $table->index(['user_id', 'created_at']);
        });

        /*
         * One open cart per customer.
         *
         * A partial unique index rather than a status column somebody has to remember to
         * check: two open carts is not a feature, it is a customer whose items are split
         * across two places and who can only see one of them.
         *
         * Carts are kept after checkout rather than deleted, because "what was in the
         * basket when they abandoned it" is the question every commerce team asks first.
         */
        Schema::create('carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('status', 20)->default('open');
            $table->string('currency', 3)->default('TRY');

            /*
             * Where the basket came from, when it came from a design. Kept so a customer
             * can see which room they were shopping for, and so the design can show what
             * has been bought from it.
             */
            $table->uuid('design_version_id')->nullable();
            $table->foreign('design_version_id')->references('id')->on('design_versions')->nullOnDelete();

            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampTz('checked_out_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE carts
            ADD CONSTRAINT carts_status_check
            CHECK (status IN ('open', 'checking_out', 'ordered', 'abandoned'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX carts_one_open_per_user
            ON carts (user_id)
            WHERE status IN ('open', 'checking_out')
        SQL);

        /*
         * One line: a SKU, a quantity, and what it cost when it went in.
         *
         * The seller is denormalised from the SKU because every read of a cart groups by
         * it, and because a line has to keep saying who was selling it even if the offer is
         * later withdrawn — a basket that forgets which shop an item came from is a basket
         * nobody can turn into an order.
         */
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('cart_id');
            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();

            $table->uuid('sku_id');
            $table->foreign('sku_id')->references('id')->on('product_skus')->cascadeOnDelete();

            $table->uuid('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->unsignedSmallInteger('quantity')->default(1);

            /*
             * What it cost when it was added, and what it listed at. Both, because a
             * discount that ends is a different sentence from a price that rose, and a
             * customer deserves to be told which happened.
             */
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('list_price_minor');
            $table->unsignedInteger('tax_rate_bps')->default(2000);

            /*
             * When the snapshot stopped matching. Set by revalidation rather than by a
             * trigger, because "the price moved" is a thing to tell somebody once, not a
             * comparison to recompute on every read.
             */
            $table->timestampTz('price_changed_at')->nullable();

            // Which suggestion this came from, when it came from a design.
            $table->uuid('design_match_id')->nullable();
            $table->foreign('design_match_id')->references('id')->on('design_matches')->nullOnDelete();

            $table->timestampsTz();

            /*
             * The same offer added twice raises the quantity rather than making a second
             * line. Enforced here, because two lines for one SKU is a basket that shows the
             * customer two of something they think they added once.
             */
            $table->unique(['cart_id', 'sku_id']);
            $table->index(['cart_id', 'seller_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cart_items
            ADD CONSTRAINT cart_items_quantity_check
            CHECK (quantity > 0 AND quantity <= 99)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE cart_items
            ADD CONSTRAINT cart_items_price_check
            CHECK (unit_price_minor >= 0 AND list_price_minor >= 0 AND tax_rate_bps <= 10000)
        SQL);

        /*
         * A trigram index on the product name.
         *
         * The catalogue search matches on `name ILIKE '%…%'`, which without this is a
         * sequential scan over every product — fine at a thousand rows, not at a hundred
         * thousand. `pg_trgm` also survives the typos a search box actually receives.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX products_name_trigram_idx
            ON products
            USING gin (name gin_trgm_ops)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_name_trigram_idx');

        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('favorites');
    }
};

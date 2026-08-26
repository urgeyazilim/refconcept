<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was bought, from whom, and how far along it is.
 *
 * A marketplace order is two things at once and the schema says so. The **customer**
 * bought one thing: they paid once, at one moment, for a basket, and they will ask about
 * it by one number. The **sellers** each received a separate instruction: their own parcel,
 * their own warehouse, their own courier, their own money. Modelling only the first leaves
 * every seller screen filtering a shared table by hand; modelling only the second leaves a
 * customer with three orders they never placed.
 *
 * So there is a master `orders` row per payment and a `seller_orders` row per seller in it,
 * and the line items belong to both.
 *
 * **Everything is a snapshot.** The product name, the SKU code, the price, the tax rate and
 * the commission are all copied onto the line at the moment of the order. A product renamed
 * next month must not change what an invoice from last month says it was, and a seller who
 * renegotiates their commission must not retroactively change what they earned. This is the
 * single most important property in the file: an order is a record of an event, not a view
 * over the current state of the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The number a customer quotes on the phone.
         *
         * A sequence rather than the UUID: nobody reads out a UUID, and a support call
         * that starts with "01a03b..." is a support call that goes wrong. Kept separate
         * from the primary key so the id stays a UUIDv7 like everything else.
         */
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START 1000');

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('order_number', 24);

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            /*
             * The checkout this came from. Unique: one payment produces one order, and
             * that is the entire duplicate defence — a webhook delivered four times cannot
             * make four orders, because the second insert loses to this index.
             */
            $table->uuid('checkout_session_id');
            $table->foreign('checkout_session_id')->references('id')->on('checkout_sessions')->cascadeOnDelete();

            $table->uuid('payment_intent_id')->nullable();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();

            $table->string('status', 32)->default('paid');

            $table->string('currency', 3)->default('TRY');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('grand_total_minor');

            // Copied, not linked: editing an address book next month must not change where
            // last month's parcel was promised, and this is what an invoice is drawn from.
            $table->jsonb('shipping_address');
            $table->jsonb('billing_address');

            $table->string('customer_email', 255)->nullable();
            $table->string('customer_note', 500)->nullable();

            $table->timestampTz('placed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'placed_at']);
            $table->index(['status', 'placed_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_check
            CHECK (status IN (
                'paid', 'processing', 'partially_shipped', 'shipped',
                'delivered', 'cancelled', 'refunded', 'partially_refunded'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT orders_totals_check
            CHECK (
                subtotal_minor >= 0 AND discount_minor >= 0 AND shipping_minor >= 0
                AND tax_minor >= 0 AND grand_total_minor >= 0
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX orders_number_unique ON orders (order_number)');

        // One payment, one order. The whole defence against a duplicate confirmation
        // producing a duplicate order, expressed where it cannot be forgotten.
        DB::statement('CREATE UNIQUE INDEX orders_one_per_checkout ON orders (checkout_session_id)');

        /*
         * One seller's part of an order.
         *
         * Its own status, because the states are genuinely different: a customer's order is
         * "shipped" when everything has gone, a seller's when *theirs* has. Its own number
         * too — a seller quoting the master number would be reading out a document that
         * lists another seller's goods and another seller's money.
         */
        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('seller_order_number', 32);

            $table->string('status', 32)->default('awaiting_confirmation');

            $table->string('currency', 3)->default('TRY');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('total_minor');

            /*
             * What the platform will keep, snapshotted.
             *
             * Phase 16 builds the resolver hierarchy and the ledger; the *snapshot* belongs
             * here because it has to be taken at order time. A seller who renegotiates
             * their rate next quarter must not retroactively change what they earned on
             * last quarter's sales.
             */
            $table->bigInteger('commission_minor')->default(0);

            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            $table->string('cancellation_reason', 300)->nullable();

            $table->timestampsTz();

            $table->index(['seller_id', 'status']);

            // No plain index on (order_id, seller_id): the unique index below covers the
            // same columns in the same order, and a second copy would only add a write on
            // every insert. A duplicate index is invisible from the outside, which is why
            // it survives — nothing gets slower, the table just pays twice.
        });

        DB::statement(<<<'SQL'
            ALTER TABLE seller_orders
            ADD CONSTRAINT seller_orders_status_check
            CHECK (status IN (
                'awaiting_confirmation', 'confirmed', 'preparing',
                'shipped', 'delivered', 'cancelled', 'returned'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE seller_orders
            ADD CONSTRAINT seller_orders_amount_check
            CHECK (
                subtotal_minor >= 0 AND tax_minor >= 0 AND shipping_minor >= 0
                AND total_minor >= 0 AND commission_minor >= 0
                AND commission_minor <= total_minor
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX seller_orders_number_unique ON seller_orders (seller_order_number)');

        // One seller appears once in an order. Two rows would split their own parcel from
        // itself, and their payout with it.
        DB::statement('CREATE UNIQUE INDEX seller_orders_one_per_seller ON seller_orders (order_id, seller_id)');

        /*
         * A line, frozen.
         *
         * Belongs to both the master order and the seller order — the customer reads it as
         * one basket and the seller works from their own list, and neither should have to
         * join through the other to get it.
         *
         * The names and codes are copied rather than joined. A product renamed, unlisted or
         * deleted must not change what an order from last month says was bought, and an
         * order that renders differently after a catalogue edit is not a record of
         * anything.
         */
        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->uuid('seller_order_id');
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->cascadeOnDelete();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            // Nulled rather than cascaded: a deleted product must not take the record of
            // having sold it with it.
            $table->uuid('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            $table->uuid('sku_id')->nullable();
            $table->foreign('sku_id')->references('id')->on('product_skus')->nullOnDelete();

            $table->string('product_name', 255);
            $table->string('sku_code', 64)->nullable();
            $table->string('variant_label', 160)->nullable();
            $table->string('image_url', 512)->nullable();

            $table->unsignedSmallInteger('quantity');

            $table->bigInteger('unit_price_minor');
            $table->bigInteger('list_price_minor')->nullable();
            $table->unsignedInteger('tax_rate_bps')->default(2000);
            $table->bigInteger('line_total_minor');
            $table->bigInteger('tax_minor')->default(0);

            // Snapshotted at order time — see the seller_orders comment.
            $table->unsignedInteger('commission_rate_bps')->default(0);
            $table->bigInteger('commission_minor')->default(0);

            // Which AI suggestion this came from, when it came from a design. Kept so a
            // design can show what was actually bought from it.
            $table->uuid('design_match_id')->nullable();
            $table->foreign('design_match_id')->references('id')->on('design_matches')->nullOnDelete();

            $table->timestampsTz();

            $table->index(['order_id', 'seller_id']);
            $table->index('seller_order_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_amount_check
            CHECK (
                quantity > 0
                AND unit_price_minor >= 0
                AND line_total_minor >= 0
                AND tax_rate_bps <= 10000
                AND commission_rate_bps <= 10000
            )
        SQL);

        /*
         * Every status change, append-only.
         *
         * Not a log: "when did this become shipped, and who said so" is the question every
         * dispute starts with, and a table that can be edited cannot answer it. The trigger
         * makes that true in the database rather than by convention.
         */
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('order_id')->nullable();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->uuid('seller_order_id')->nullable();
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            // 'seller', 'customer', 'operator', 'system' — who the actor was acting as,
            // which is not always answerable from the user id alone.
            $table->string('actor_role', 20)->default('system');
            $table->string('reason', 300)->nullable();

            $table->timestampTz('created_at');

            $table->index(['order_id', 'created_at']);
            $table->index(['seller_order_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_order_status_history_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'order_status_history is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_status_history_no_update
            BEFORE UPDATE OR DELETE ON order_status_history
            FOR EACH ROW EXECUTE FUNCTION refconcept_order_status_history_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_status_history_no_update ON order_status_history');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_order_status_history_append_only()');

        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('seller_orders');
        Schema::dropIfExists('orders');

        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};

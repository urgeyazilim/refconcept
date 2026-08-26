<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Getting goods to a customer, and back again when it goes wrong.
 *
 * Three things that look like one and are not:
 *
 *   **A shipment** is a physical parcel with a carrier and a tracking number. A seller
 *   order can be more than one — a sofa and its cushions leave on different days — so
 *   "shipped" is a property of a parcel, not of an order.
 *
 *   **A return** is the customer wanting to send something back. It has its own lifecycle
 *   and its own clock, and it is *requested*, not decided: a seller inspects what arrives.
 *
 *   **A refund** is money moving. It is deliberately separate from the return, because
 *   goods and money travel on different timetables and the commonest way to lose track of
 *   either is to pretend one implies the other. A return can be approved and the refund
 *   fail at the provider; a refund can be issued as goodwill with nothing coming back.
 *
 * Everything here is per **line**, not per order. A customer who bought four chairs and
 * wants to return one is the normal case, and an order-level model turns it into a support
 * conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One parcel.
         *
         * The carrier and tracking number are text rather than an integration: a seller
         * ships with whoever they ship with, and a marketplace that only accepts three
         * carriers has told its sellers how to run their warehouse.
         */
        Schema::create('shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('seller_order_id');
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->cascadeOnDelete();

            $table->string('carrier', 80)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('tracking_url', 512)->nullable();

            $table->string('status', 24)->default('preparing');

            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            // What the customer is told, in a sentence. A carrier's own status codes are
            // not something anybody outside a logistics team can read.
            $table->string('note', 300)->nullable();

            $table->timestampsTz();

            $table->index(['seller_order_id', 'status']);
            $table->index('tracking_number');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shipments
            ADD CONSTRAINT shipments_status_check
            CHECK (status IN ('preparing', 'shipped', 'in_transit', 'delivered', 'lost', 'returned'))
        SQL);

        /*
         * Which lines went in which parcel.
         *
         * A join table because a parcel holds several lines and a line can be split across
         * parcels — three of four chairs today, the fourth when it arrives. Without this,
         * "what is still coming" is a question nobody can answer.
         */
        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('shipment_id');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();

            $table->uuid('order_item_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();

            $table->unsignedSmallInteger('quantity');

            $table->timestampTz('created_at')->nullable();

            $table->index('shipment_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shipment_items
            ADD CONSTRAINT shipment_items_quantity_check
            CHECK (quantity > 0)
        SQL);

        // The same line twice in one parcel is two rows saying one thing, and a quantity
        // nobody can reconcile against what was ordered.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX shipment_items_one_per_line
            ON shipment_items (shipment_id, order_item_id)
        SQL);

        /*
         * A customer wanting to send something back.
         *
         * Per seller order, because that is who receives the parcel — a return spanning
         * three sellers is three returns, however it looked to the customer when they
         * pressed the button.
         */
        Schema::create('returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('reference', 32);

            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->uuid('seller_order_id');
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->cascadeOnDelete();

            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();

            $table->string('status', 24)->default('requested');

            $table->string('reason_code', 40);
            $table->string('reason_note', 500)->nullable();

            $table->string('currency', 3)->default('TRY');

            // What the customer is asking for, and what was decided. Separate, because a
            // seller can accept two of three items.
            $table->bigInteger('requested_minor')->default(0);
            $table->bigInteger('approved_minor')->default(0);

            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestampTz('received_at')->nullable();

            $table->timestampsTz();

            $table->index(['seller_order_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE returns
            ADD CONSTRAINT returns_status_check
            CHECK (status IN (
                'requested', 'approved', 'rejected', 'in_transit',
                'received', 'completed', 'cancelled'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE returns
            ADD CONSTRAINT returns_amount_check
            CHECK (requested_minor >= 0 AND approved_minor >= 0 AND approved_minor <= requested_minor)
        SQL);

        DB::statement('CREATE UNIQUE INDEX returns_reference_unique ON returns (reference)');

        /*
         * One line inside a return.
         *
         * `quantity` is what the customer wants to send back and `approved_quantity` what
         * the seller accepted, and the two being separate columns is the whole reason
         * partial returns work at all.
         */
        Schema::create('return_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('return_id');
            $table->foreign('return_id')->references('id')->on('returns')->cascadeOnDelete();

            $table->uuid('order_item_id');
            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();

            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('approved_quantity')->default(0);

            // Snapshotted from the order line: what a refund is calculated from must not
            // move when a seller reprices.
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('refund_minor')->default(0);
            $table->unsignedInteger('commission_rate_bps')->default(0);

            $table->string('condition_note', 300)->nullable();

            $table->timestampsTz();

            $table->index('return_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE return_items
            ADD CONSTRAINT return_items_quantity_check
            CHECK (quantity > 0 AND approved_quantity <= quantity)
        SQL);

        // One row per order line per return: two would let the same chair be returned
        // twice inside one request.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX return_items_one_per_line
            ON return_items (return_id, order_item_id)
        SQL);

        /*
         * Money going back.
         *
         * Its own table and its own lifecycle, deliberately separate from the return.
         * Goods and money travel on different timetables: a return can be approved and the
         * refund fail at the provider, and a refund can be issued as goodwill with nothing
         * coming back. Modelling them as one field makes both of those impossible to
         * represent and therefore impossible to fix.
         */
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('reference', 32);

            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->uuid('seller_order_id')->nullable();
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->nullOnDelete();

            $table->uuid('return_id')->nullable();
            $table->foreign('return_id')->references('id')->on('returns')->nullOnDelete();

            $table->uuid('payment_intent_id')->nullable();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();

            $table->string('status', 24)->default('pending');
            $table->string('currency', 3)->default('TRY');

            $table->bigInteger('amount_minor');

            // How the total splits, so the reversal can be posted without recomputing it
            // from tables that may have moved since.
            $table->bigInteger('seller_share_minor')->default(0);
            $table->bigInteger('commission_share_minor')->default(0);

            $table->string('reason', 300)->nullable();
            $table->string('failure_reason', 300)->nullable();

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('processed_at')->nullable();

            $table->timestampsTz();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT refunds_status_check
            CHECK (status IN ('pending', 'processing', 'succeeded', 'failed', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT refunds_amount_check
            CHECK (
                amount_minor > 0
                AND seller_share_minor >= 0
                AND commission_share_minor >= 0
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX refunds_reference_unique ON refunds (reference)');

        /*
         * One refund per return.
         *
         * A partial unique index over the states that still count, so a failed attempt can
         * be retried with a new row while a live one cannot be duplicated. Refunding twice
         * for one return is money that does not come back.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX refunds_one_live_per_return
            ON refunds (return_id)
            WHERE return_id IS NOT NULL AND status IN ('pending', 'processing', 'succeeded')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};

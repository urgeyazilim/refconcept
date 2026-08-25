<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taking money: the session, the intent, the attempts, and what the bank told us.
 *
 * Four tables and one rule holding them together — **every one of them can be asked the
 * same question twice and must give the same answer**. Payment providers retry. They send
 * the same webhook four times, they call back after the browser already returned, they
 * time out and then succeed. A payment system that is not idempotent end to end does not
 * fail loudly; it charges somebody twice, or credits an account twice, and nobody notices
 * until the reconciliation.
 *
 * So the shape is:
 *
 *   checkout_sessions   what the customer agreed to, frozen. Prices, addresses, totals.
 *   payment_intents     one attempt to collect one amount. The state machine lives here.
 *   payment_transactions every call to a provider and its outcome. Append-only.
 *   payment_webhook_events the inbox: received first, understood later, never twice.
 *   idempotency_keys    the same guarantee, offered to our own API clients.
 *
 * The money columns are all integer minor units. Never a float, in any of them, ever:
 * 0.1 + 0.2 is not 0.3 in binary floating point, and a payments table is the last place
 * to discover that.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the customer agreed to buy, frozen at the moment they said so.
         *
         * A snapshot rather than a set of foreign keys, and that is the entire point. The
         * cart can still be edited. The address book can be edited. The seller can reprice
         * tomorrow. None of that may change what this customer is being charged, or what
         * address a parcel was promised to — so the session copies the numbers and the
         * address text in, and stops asking.
         *
         * `purpose` distinguishes a basket from a credit top-up. They are the same
         * transaction to a bank and two different things to us, and the fulfilment step
         * needs to know which without guessing from whether cart_id is null.
         */
        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('purpose', 20);
            $table->string('status', 24)->default('open');

            // Null for a credit purchase: there is no basket, only a package.
            $table->uuid('cart_id')->nullable();
            $table->foreign('cart_id')->references('id')->on('carts')->nullOnDelete();

            $table->uuid('credit_package_id')->nullable();
            $table->foreign('credit_package_id')->references('id')->on('credit_packages')->nullOnDelete();

            /*
             * The addresses as text, not as a link to the address book.
             *
             * Somebody editing their saved address next month must not retroactively
             * change where last month's parcel was sent. This is also the copy an invoice
             * is drawn from, so it has to be the copy that existed at the time.
             */
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();

            $table->string('currency', 3)->default('TRY');

            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);

            // Contained *in* the total, not added to it — Turkish prices are quoted
            // inclusive of KDV. Recorded because an invoice has to state it.
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('grand_total_minor')->default(0);

            /*
             * The lines as they stood. Kept even for a basket, because the cart rows are
             * mutable and this is the thing a dispute is settled against.
             */
            $table->jsonb('lines')->nullable();

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_sessions
            ADD CONSTRAINT checkout_sessions_purpose_check
            CHECK (purpose IN ('cart', 'credits'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_sessions
            ADD CONSTRAINT checkout_sessions_status_check
            CHECK (status IN ('open', 'awaiting_payment', 'paid', 'cancelled', 'expired', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE checkout_sessions
            ADD CONSTRAINT checkout_sessions_totals_check
            CHECK (
                subtotal_minor >= 0
                AND discount_minor >= 0
                AND shipping_minor >= 0
                AND tax_minor >= 0
                AND grand_total_minor >= 0
            )
        SQL);

        /*
         * One live session per purpose per customer.
         *
         * Two open basket checkouts would mean two stock holds for one basket and a
         * customer who can pay the one they are not looking at. A separate slot for
         * credits, because buying credits mid-checkout is a reasonable thing to do and
         * refusing it would be a rule invented by the schema rather than by the business.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX checkout_sessions_one_live_per_purpose
            ON checkout_sessions (user_id, purpose)
            WHERE status IN ('open', 'awaiting_payment')
        SQL);

        /*
         * One attempt to collect one amount.
         *
         * Deliberately *not* one row per checkout: a customer whose card is declined tries
         * again, possibly with another card or another provider, and each of those is its
         * own conversation with its own external id and its own outcome. Collapsing them
         * into one row loses the history that a chargeback argument is made from.
         *
         * `status` is the state machine. It is written only through the transitions
         * defined in PaymentStatus, and the CHECK below is the second line of defence —
         * a value that reaches the column another way is still a value we recognise.
         */
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('checkout_session_id');
            $table->foreign('checkout_session_id')->references('id')->on('checkout_sessions')->cascadeOnDelete();

            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('gateway', 40);
            $table->string('method', 32)->default('card');
            $table->string('status', 32)->default('created');

            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('TRY');

            // What has actually landed and what has gone back. Both counters, because a
            // partial refund is normal and "refunded: yes/no" cannot describe it.
            $table->bigInteger('captured_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);

            /*
             * The provider's own id for this payment. Unique per gateway where present —
             * two of our intents pointing at one provider payment means we have lost track
             * of which one the money belongs to.
             */
            $table->string('external_id', 191)->nullable();

            // Where to send the browser for 3DS, when the provider needs a step we cannot
            // do server-side. Never contains card data; it is a URL the provider gave us.
            $table->text('redirect_url')->nullable();

            /*
             * The provider's non-sensitive echo: masked pan, scheme, instalments, its own
             * status string. Redacted before it is written — a PAN or a CVV must never
             * reach this column, and the redaction is done in code rather than trusted to
             * the provider to have omitted.
             */
            $table->jsonb('details')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 200)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['checkout_session_id', 'status']);
            $table->index(['gateway', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_status_check
            CHECK (status IN (
                'created', 'requires_action', 'processing', 'authorized',
                'captured', 'partially_refunded', 'refunded',
                'failed', 'cancelled', 'expired'
            ))
        SQL);

        /*
         * Money out never exceeds money in, and neither is negative.
         *
         * Cheap to state here and impossible to violate by accident afterwards. A refund
         * larger than the capture is not a business decision anybody makes on purpose; it
         * is a bug, and this is where it stops.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_amounts_check
            CHECK (
                amount_minor > 0
                AND captured_minor >= 0
                AND refunded_minor >= 0
                AND captured_minor <= amount_minor
                AND refunded_minor <= captured_minor
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_intents_gateway_external_id
            ON payment_intents (gateway, external_id)
            WHERE external_id IS NOT NULL
        SQL);

        /*
         * At most one intent still in flight per session.
         *
         * The customer who clicks "pay" twice gets one payment. Without this the second
         * click starts a second conversation with the bank while the first is still open,
         * and both can succeed.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_intents_one_live_per_session
            ON payment_intents (checkout_session_id)
            WHERE status IN ('created', 'requires_action', 'processing', 'authorized')
        SQL);

        /*
         * Every call we made to a provider, and what came back. Append-only.
         *
         * This is the financial record. Not a log — a log is something you delete when the
         * disk fills. When a customer says they were charged twice, or a provider says a
         * refund was never requested, this table is the answer, and it is only an answer
         * if nothing can quietly edit it. The trigger below makes that true in the
         * database, where a raw UPDATE cannot walk past it.
         */
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('payment_intent_id');
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->cascadeOnDelete();

            $table->string('gateway', 40);
            $table->string('type', 24);
            $table->string('status', 20);

            // Always positive; the direction is the type. A signed amount would let a
            // refund be written as a negative capture, and then no query means anything.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('TRY');

            $table->string('external_id', 191)->nullable();
            $table->string('external_reference', 191)->nullable();

            /*
             * What we sent, reduced to a fingerprint, and what came back, redacted.
             *
             * The request itself is not stored: it contains whatever the provider needed,
             * and the cost of one day storing a PAN by accident is not worth the debugging
             * convenience. The fingerprint is enough to tell "the same call again" from
             * "a different call".
             */
            $table->string('request_fingerprint', 64)->nullable();
            $table->jsonb('response')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 200)->nullable();

            // Supplied by the caller so a retried call is recognised as the same call.
            $table->string('idempotency_key', 191)->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['payment_intent_id', 'occurred_at']);
            $table->index(['gateway', 'external_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_transactions
            ADD CONSTRAINT payment_transactions_type_check
            CHECK (type IN ('authorize', 'capture', 'sale', 'cancel', 'refund', 'chargeback', 'query'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_transactions
            ADD CONSTRAINT payment_transactions_status_check
            CHECK (status IN ('pending', 'succeeded', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_transactions
            ADD CONSTRAINT payment_transactions_amount_check
            CHECK (amount_minor >= 0)
        SQL);

        /*
         * The same operation, keyed the same way, happens once.
         *
         * This is the index that stops a retried capture from capturing twice. The
         * application checks first and this catches the race where two workers checked at
         * the same instant and both found nothing.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_transactions_idempotent_operation
            ON payment_transactions (payment_intent_id, type, idempotency_key)
            WHERE idempotency_key IS NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_payment_transactions_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payment_transactions is append-only; record a compensating transaction instead';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_transactions_no_update
            BEFORE UPDATE OR DELETE ON payment_transactions
            FOR EACH ROW EXECUTE FUNCTION refconcept_payment_transactions_append_only();
        SQL);

        /*
         * The inbox.
         *
         * A webhook is *persisted before it is understood*. The provider is waiting on the
         * other end of the socket and will retry if we are slow, so the endpoint's only
         * job is to write the row and say 200; the meaning is worked out by a queued job
         * afterwards. Doing the domain work inline is how a slow database turns into a
         * provider retry storm and then into a double credit.
         *
         * Deduplication is on two keys, because providers are inconsistent about giving
         * events an id:
         *   - the provider's event id, when it has one, and
         *   - a fingerprint of the raw body, which always exists.
         * Either colliding means we have seen this before and must do nothing.
         */
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('gateway', 40);
            $table->string('event_type', 64)->nullable();
            $table->string('external_event_id', 191)->nullable();

            // sha-256 of the exact bytes received, before any parsing.
            $table->char('body_fingerprint', 64);

            /*
             * Whether the signature checked out. Stored rather than acted on at the door,
             * because an unsigned event that claims a payment succeeded is itself worth
             * keeping and looking at — it is either a misconfiguration or an attack, and
             * both deserve a row.
             */
            $table->boolean('signature_verified')->default(false);

            $table->jsonb('headers')->nullable();
            $table->jsonb('payload')->nullable();

            $table->string('status', 20)->default('received');

            $table->uuid('payment_intent_id')->nullable();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_message', 300)->nullable();

            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            $table->timestampsTz();

            $table->index(['gateway', 'status']);
            $table->index(['status', 'received_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_webhook_events
            ADD CONSTRAINT payment_webhook_events_status_check
            CHECK (status IN ('received', 'processing', 'processed', 'ignored', 'failed'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_webhook_events_external_id
            ON payment_webhook_events (gateway, external_event_id)
            WHERE external_event_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_webhook_events_fingerprint
            ON payment_webhook_events (gateway, body_fingerprint)
        SQL);

        /*
         * The same guarantee, offered outward.
         *
         * A customer's browser on a bad connection retries a POST. A mobile app retries on
         * timeout. Both are the same request and must produce the same result, not a
         * second payment — so a client may send an Idempotency-Key and get its first
         * answer back, byte for byte, however many times it asks.
         *
         * The stored fingerprint of the request body matters: reusing a key with different
         * content is not a retry, it is a mistake, and it is refused rather than silently
         * answered with somebody else's result.
         */
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('scope', 120);
            $table->string('key', 191);

            $table->char('request_fingerprint', 64);

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();

            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            $table->timestampsTz();

            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idempotency_keys_scope_key
            ON idempotency_keys (scope, key, COALESCE(user_id, '00000000-0000-0000-0000-000000000000'::uuid))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('payment_webhook_events');

        DB::unprepared('DROP TRIGGER IF EXISTS payment_transactions_no_update ON payment_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_payment_transactions_append_only()');

        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('checkout_sessions');
    }
};

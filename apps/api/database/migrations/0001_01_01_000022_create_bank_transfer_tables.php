<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paying by bank transfer, which is a payment method with no provider in it.
 *
 * Everything else in Phase 11 talks to a gateway that answers in seconds. This one talks
 * to a bank, through a customer, over a day or two, and is confirmed by a person reading a
 * statement. That changes three things and the schema is shaped by all three:
 *
 *  1. **The reference is the whole mechanism.** A transfer arrives as a line on a
 *     statement with a name and an amount and very little else. The reference the customer
 *     types into their banking app is the only thing tying that line to an order, so it is
 *     unique, short enough to type, and made of characters nobody misreads.
 *
 *  2. **The amount is a claim, not a fact.** People transfer the wrong figure — a typo, a
 *     bank fee deducted in transit, two orders paid in one go. Short and over payments are
 *     therefore explicit states rather than a boolean that has to be true, because the
 *     alternative is an operator quietly deciding that 4.997,50₺ is close enough to 5.000₺.
 *
 *  3. **A confirmation moves real money and cannot be undone.** It is recorded with who
 *     did it, when, against which statement date, and it can happen exactly once — the
 *     rules in 06_SECURITY_PAYMENT_FINANCE_RULES.md say duplicate confirmation is blocked,
 *     and here that is a unique index rather than a check somebody remembers to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The accounts customers pay into.
         *
         * The IBAN is stored in plain text, and that is a deliberate exception to the rule
         * that IBANs are encrypted at rest. That rule protects *sellers'* payout details,
         * which are personal data nobody outside finance should see. These are the
         * platform's own receiving accounts: they are printed on the checkout page for
         * every customer to copy. Encrypting a number we publish would be security
         * theatre — it would cost a key rotation and protect nothing.
         */
        Schema::create('payment_bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('bank_name', 120);
            $table->string('branch', 120)->nullable();
            $table->string('account_holder', 160);

            $table->string('iban', 34);
            $table->string('currency', 3)->default('TRY');

            // Shown under the account, for the things that vary by bank: cut-off times,
            // which transfer types are accepted, what not to write in the description.
            $table->string('note', 300)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'position']);
        });

        // The same account listed twice is a customer choosing between two identical
        // options and an operator wondering which one the money arrived in.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payment_bank_accounts_iban_unique
            ON payment_bank_accounts (iban)
        SQL);

        /*
         * One expected transfer.
         *
         * Hangs off a payment intent rather than replacing it, so a bank transfer is the
         * same kind of object as a card payment: the same state machine, the same
         * append-only transaction record, the same fulfilment path. Only the way the money
         * arrives is different, and only this table knows about it.
         */
        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('payment_intent_id');
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->cascadeOnDelete();

            $table->uuid('bank_account_id')->nullable();
            $table->foreign('bank_account_id')->references('id')->on('payment_bank_accounts')->nullOnDelete();

            /*
             * What the customer types into their banking app.
             *
             * Uppercase, grouped, and drawn from an alphabet with no 0/O and no 1/I/L —
             * the reference is copied by hand from a screen into a phone, and a character
             * pair that looks the same in one font is a payment nobody can match.
             */
            $table->string('reference', 32);

            $table->string('status', 24)->default('awaiting_transfer');

            $table->bigInteger('expected_minor');
            $table->bigInteger('received_minor')->nullable();
            $table->string('currency', 3)->default('TRY');

            /*
             * The date the bank says the money landed, which is not the date somebody
             * confirmed it. Reconciliation is done against statements, and a statement is
             * organised by value date.
             */
            $table->date('value_date')->nullable();

            $table->timestampTz('expires_at')->nullable();

            $table->uuid('confirmed_by')->nullable();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();

            // Why an operator decided what they decided. Mandatory for anything but a
            // clean confirmation, because an unexplained financial decision is
            // indistinguishable from a mistake six months later.
            $table->string('decision_note', 300)->nullable();

            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('value_date');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bank_transfers
            ADD CONSTRAINT bank_transfers_status_check
            CHECK (status IN (
                'awaiting_transfer', 'under_review', 'confirmed',
                'short_paid', 'over_paid', 'rejected', 'expired'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bank_transfers
            ADD CONSTRAINT bank_transfers_amount_check
            CHECK (expected_minor > 0 AND (received_minor IS NULL OR received_minor >= 0))
        SQL);

        /*
         * The reference is unique across every transfer that has ever existed, not merely
         * the live ones.
         *
         * A reused reference would match an incoming payment to the wrong order, and the
         * two orders involved would be a customer who paid and got nothing and a customer
         * who got something free. Statements are also reconciled months later, long after
         * a transfer stops being live.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_transfers_reference_unique
            ON bank_transfers (reference)
        SQL);

        /*
         * One live transfer per payment.
         *
         * Two would mean two references quoted for one order and an operator with no way
         * to tell which of them the money in the statement belongs to.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_transfers_one_live_per_intent
            ON bank_transfers (payment_intent_id)
            WHERE status IN ('awaiting_transfer', 'under_review')
        SQL);

        /*
         * A transfer is confirmed once.
         *
         * The rule in 06_SECURITY_PAYMENT_FINANCE_RULES.md, expressed where it cannot be
         * forgotten: a partial unique index on the intent for the settled states. A second
         * operator pressing confirm on a stale screen loses to the index rather than
         * releasing an order twice.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_transfers_one_settled_per_intent
            ON bank_transfers (payment_intent_id)
            WHERE status IN ('confirmed', 'over_paid')
        SQL);

        /*
         * The receipt a customer uploads.
         *
         * A bank's own PDF or a screenshot of an app, which means it can carry an account
         * number, a balance and a full name. Private disk, random key, no URL in any
         * response — the same tier as seller onboarding documents, for the same reason.
         *
         * More than one is allowed: the first upload is frequently the wrong file.
         */
        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('bank_transfer_id');
            $table->foreign('bank_transfer_id')->references('id')->on('bank_transfers')->cascadeOnDelete();

            $table->uuid('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->string('original_name', 255);
            $table->string('storage_path', 512);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            $table->timestampTz('created_at');

            $table->index(['bank_transfer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('bank_transfers');
        Schema::dropIfExists('payment_bank_accounts');
    }
};

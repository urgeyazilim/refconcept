<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the money is, who it belongs to, and how it got there.
 *
 * A marketplace holds money it does not own. A customer pays once; some of that is the
 * platform's commission and the rest is owed to several sellers, held until goods are
 * delivered and a return window has closed. Getting that wrong is not a bug that shows up
 * as a broken page — it shows up months later as a payout that cannot be explained.
 *
 * So the record is a **double-entry journal**, as 06_SECURITY_PAYMENT_FINANCE_RULES.md
 * requires. Every event is a set of lines that sum to zero, and nothing is ever edited or
 * deleted: a mistake is corrected by a reversing entry, so both the mistake and the
 * correction stay visible. That is the difference between a ledger and a table of numbers.
 *
 * `seller_balances` is a *projection* of the journal, not a source. It exists because
 * summing every line a seller has ever had on every page load is not a system, and it is
 * recomputable from the journal at any time — which is what makes it safe to have.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the platform keeps, and the rules that decide it.
         *
         * The hierarchy in the finance rules has six rungs; five of them are rows in this
         * table and the sixth is the order item's own snapshot. `priority` is stored rather
         * than derived from `scope` so an operator can put a campaign above a negotiated
         * seller rate without a migration.
         */
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('scope', 20);

            $table->uuid('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->uuid('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();

            // Basis points, never a percentage: 1250 bps is 12,5% and cannot be stored as
            // 12.5 without inviting a float into the one place floats must never go.
            $table->unsignedInteger('rate_bps');

            $table->unsignedSmallInteger('priority')->default(100);

            $table->string('label', 160)->nullable();

            // A campaign runs for a window; a negotiated rate usually does not.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['scope', 'is_active']);
            $table->index(['seller_id', 'category_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE commission_rules
            ADD CONSTRAINT commission_rules_scope_check
            CHECK (scope IN ('platform', 'category', 'seller', 'seller_category', 'campaign'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE commission_rules
            ADD CONSTRAINT commission_rules_rate_check
            CHECK (rate_bps <= 10000)
        SQL);

        /*
         * A scope means what its name says.
         *
         * A `seller` rule with a category, or a `category` rule with a seller, is a row
         * whose meaning depends on which column the reader happens to look at — and the
         * resolver would then have to guess. Stated here so it cannot be written at all.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE commission_rules
            ADD CONSTRAINT commission_rules_shape_check
            CHECK (
                (scope = 'platform' AND seller_id IS NULL AND category_id IS NULL)
                OR (scope = 'category' AND seller_id IS NULL AND category_id IS NOT NULL)
                OR (scope = 'seller' AND seller_id IS NOT NULL AND category_id IS NULL)
                OR (scope = 'seller_category' AND seller_id IS NOT NULL AND category_id IS NOT NULL)
                OR (scope = 'campaign')
            )
        SQL);

        // Exactly one live platform default. Two would make "the fallback" a coin toss.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX commission_rules_one_platform_default
            ON commission_rules ((scope))
            WHERE scope = 'platform' AND is_active
        SQL);

        /*
         * The chart of accounts.
         *
         * Codes follow the naming in the finance rules — `LIABILITY:SELLER_PAYABLE` and so
         * on — with the seller held in its own column rather than interpolated into the
         * code. A code that has to be parsed to be understood is a code somebody will parse
         * wrongly.
         */
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 64);
            $table->string('type', 16);
            $table->string('name', 160);

            $table->uuid('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('currency', 3)->default('TRY');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['type', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_accounts
            ADD CONSTRAINT ledger_accounts_type_check
            CHECK (type IN ('asset', 'liability', 'revenue', 'expense', 'clearing'))
        SQL);

        /*
         * One account per code per seller per currency.
         *
         * `COALESCE` on the seller, because a platform account has none and NULLs do not
         * collide in a unique index — which would quietly permit two REVENUE:COMMISSION
         * accounts and split the platform's income across both.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_accounts_code_unique
            ON ledger_accounts (code, currency, COALESCE(seller_id, '00000000-0000-0000-0000-000000000000'::uuid))
        SQL);

        /*
         * One financial event: a payment, a commission, a payout, a reversal.
         *
         * The header carries what happened and what it was about; the lines carry the
         * money. Append-only, enforced below — a ledger that can be edited is a ledger
         * nobody can rely on in a dispute, and disputes are the entire reason for having
         * one.
         */
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('type', 40);
            $table->string('description', 300);

            // What this entry is about, so a payment can be traced to its journal and back.
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 64)->nullable();

            /*
             * The entry this one reverses, when it is a correction.
             *
             * Never rewrite history: a mistake is corrected by a reversing entry, and both
             * stay visible. This column is what makes the pair findable.
             */
            $table->uuid('reverses_entry_id')->nullable();

            $table->string('currency', 3)->default('TRY');

            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('posted_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['type', 'posted_at']);
        });

        /*
         * The self-reference is added after the table exists.
         *
         * A foreign key onto the table being created cannot be declared inside the same
         * CREATE: the primary key it points at does not exist yet, and PostgreSQL refuses
         * with "no unique constraint matching given keys".
         */
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_reverses_fk
            FOREIGN KEY (reverses_entry_id) REFERENCES ledger_entries (id) ON DELETE SET NULL
        SQL);

        /*
         * The same event, posted twice, is one event.
         *
         * A capture confirmed four times must post one journal. The idempotency key is
         * derived from the event rather than supplied, so two callers who both believe
         * they are first collide here instead of doubling the platform's revenue.
         */
        $table = 'ledger_entries';
        DB::statement("ALTER TABLE {$table} ADD COLUMN idempotency_key varchar(191)");
        DB::statement("CREATE UNIQUE INDEX ledger_entries_idempotency ON {$table} (idempotency_key) WHERE idempotency_key IS NOT NULL");

        Schema::create('ledger_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('entry_id');
            $table->foreign('entry_id')->references('id')->on('ledger_entries')->cascadeOnDelete();

            $table->uuid('account_id');
            $table->foreign('account_id')->references('id')->on('ledger_accounts')->cascadeOnDelete();

            /*
             * Two columns rather than one signed amount.
             *
             * A signed column makes "debit" and "credit" a matter of interpretation, and
             * every report then has to agree on which sign means what. Two columns with a
             * CHECK that exactly one is filled makes the direction part of the data.
             */
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);

            $table->string('currency', 3)->default('TRY');

            // Denormalised so a seller's statement is one query rather than a join through
            // the chart of accounts on every line.
            $table->uuid('seller_id')->nullable();
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('memo', 300)->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
            $table->index(['seller_id', 'created_at']);
            $table->index('entry_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_lines
            ADD CONSTRAINT ledger_lines_direction_check
            CHECK (
                debit_minor >= 0 AND credit_minor >= 0
                AND (debit_minor = 0) <> (credit_minor = 0)
            )
        SQL);

        /*
         * Every journal balances.
         *
         * A deferred constraint trigger, which is the only way to say this in a database:
         * the check runs at commit, after every line of the entry exists, so an entry can
         * be built line by line and still be refused as a whole if it does not sum to zero.
         *
         * In code as well, of course — but the code is one caller away from being bypassed
         * and this is not.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_ledger_entry_balances()
            RETURNS trigger AS $$
            DECLARE
                total_debit bigint;
                total_credit bigint;
            BEGIN
                SELECT COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
                INTO total_debit, total_credit
                FROM ledger_lines
                WHERE entry_id = NEW.entry_id;

                IF total_debit <> total_credit THEN
                    RAISE EXCEPTION 'ledger entry % does not balance: debit % <> credit %',
                        NEW.entry_id, total_debit, total_credit;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER ledger_lines_balance
            AFTER INSERT ON ledger_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION refconcept_ledger_entry_balances();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_ledger_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'the ledger is append-only; correct a mistake with a reversing entry';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_edit
            BEFORE UPDATE OR DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION refconcept_ledger_append_only();

            CREATE TRIGGER ledger_lines_no_edit
            BEFORE UPDATE OR DELETE ON ledger_lines
            FOR EACH ROW EXECUTE FUNCTION refconcept_ledger_append_only();
        SQL);

        /*
         * What each seller is owed, as a running total.
         *
         * A projection of the journal, kept because summing a seller's whole history on
         * every page load is not a system. It is recomputable from the journal at any
         * time, which is exactly what makes it safe to keep — if it ever disagrees, the
         * journal is right and this is rebuilt.
         */
        Schema::create('seller_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('currency', 3)->default('TRY');

            // Earned but not yet payable: goods not delivered, or the hold still running.
            $table->bigInteger('pending_minor')->default(0);

            // Delivered, held out, and ready to be paid.
            $table->bigInteger('available_minor')->default(0);

            // In an approved settlement that has not been paid yet.
            $table->bigInteger('reserved_minor')->default(0);

            $table->bigInteger('paid_out_minor')->default(0);
            $table->bigInteger('lifetime_commission_minor')->default(0);

            $table->timestampTz('last_movement_at')->nullable();

            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX seller_balances_one_per_currency
            ON seller_balances (seller_id, currency)
        SQL);

        /*
         * A payout run for one seller over one period.
         *
         * Periods rather than per-order transfers, because a bank charges per transfer and
         * a seller would rather have one payment on Friday than forty over a week.
         */
        Schema::create('settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('reference', 32);

            $table->uuid('seller_id');
            $table->foreign('seller_id')->references('id')->on('sellers')->cascadeOnDelete();

            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('TRY');

            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('commission_minor')->default(0);
            $table->bigInteger('adjustment_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);

            $table->uuid('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            $table->uuid('paid_by')->nullable();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('paid_at')->nullable();

            // How the money actually left: a bank reference an operator can look up.
            $table->string('payout_reference', 191)->nullable();
            $table->string('note', 300)->nullable();

            $table->timestampsTz();

            $table->index(['seller_id', 'status']);
            $table->index(['status', 'period_end']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE settlements
            ADD CONSTRAINT settlements_status_check
            CHECK (status IN ('draft', 'approved', 'paid', 'cancelled'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE settlements
            ADD CONSTRAINT settlements_amount_check
            CHECK (gross_minor >= 0 AND commission_minor >= 0 AND net_minor >= 0)
        SQL);

        DB::statement('CREATE UNIQUE INDEX settlements_reference_unique ON settlements (reference)');

        // One open settlement per seller. Two would let the same seller order be paid out
        // in both, and a bank transfer is not something you can take back.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settlements_one_open_per_seller
            ON settlements (seller_id, currency)
            WHERE status IN ('draft', 'approved')
        SQL);

        /*
         * One seller order inside a settlement.
         *
         * The unique index is the important part: a seller order can appear in exactly one
         * settlement, ever. Without it a re-run of the builder would pay the same order
         * twice, and the second payment is a bank transfer nobody can recall.
         */
        Schema::create('settlement_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('settlement_id');
            $table->foreign('settlement_id')->references('id')->on('settlements')->cascadeOnDelete();

            $table->uuid('seller_order_id');
            $table->foreign('seller_order_id')->references('id')->on('seller_orders')->cascadeOnDelete();

            $table->bigInteger('gross_minor');
            $table->bigInteger('commission_minor');
            $table->bigInteger('net_minor');

            $table->timestampTz('created_at')->nullable();

            $table->index('settlement_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settlement_items_one_per_seller_order
            ON settlement_items (seller_order_id)
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_lines_balance ON ledger_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_lines_no_edit ON ledger_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_edit ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_ledger_entry_balances()');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_ledger_append_only()');

        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('seller_balances');
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('commission_rules');
    }
};

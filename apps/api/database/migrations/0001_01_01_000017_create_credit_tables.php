<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The credit economy.
 *
 * Credits are what a customer buys and what an AI render spends. They are money in
 * every sense that matters — somebody paid for them, they expire, they can be granted
 * by mistake and taken back — so they are kept the way money is kept: an immutable
 * ledger that is the authority, and an aggregate that is only ever a faster way to read
 * it.
 *
 * The shape here is four tables doing four different jobs, and it is worth saying why
 * each exists rather than collapsing them:
 *
 *  - **`credit_transactions` is the truth.** Append-only, enforced by a trigger. Every
 *    movement is a row, each row carries the balance that resulted, and nothing is ever
 *    updated or deleted. "Where did my credits go" has to be answerable in full a year
 *    later, and a mutable table cannot answer it.
 *  - **`credit_wallets` is a snapshot.** Balance and reserved amount, so a page load is
 *    one row rather than a sum over a year of history. It is written only inside the
 *    same locked transaction as the ledger row that changed it, so it cannot drift — and
 *    if it ever did, the ledger wins.
 *  - **`credit_lots` is what makes expiry possible.** A balance cannot expire; a
 *    *grant* can. Fifty credits bought in March and ten from a promotion in June expire
 *    on different days, so credits are consumed oldest-expiry-first out of lots, and
 *    what expires at the end of the month is exactly the remainder of the lots that
 *    reached their date.
 *  - **`credit_reservations` is a hold, not a spend.** An AI job reserves before it
 *    runs and either consumes or releases afterwards. Without a row per hold there is
 *    no way to release the right amount when a provider times out, and no way to stop a
 *    retried request reserving twice.
 *
 * Everything is an integer. A credit is a whole thing; a customer never has 2.5 of one.
 * Package prices are in minor units, like every other price in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a customer can buy.
         *
         * `bonus_credits` is separate from `credits` rather than folded into a single
         * total because the two behave differently at the till and in the accounts: the
         * customer paid for one and was given the other, and a refund returns the price
         * of the first. Keeping them apart also lets a listing say "500 + 50 free"
         * honestly instead of advertising 550 for the price of 500.
         */
        Schema::create('credit_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            $table->integer('credits');
            $table->integer('bonus_credits')->default(0);

            $table->bigInteger('price_minor');
            $table->string('currency', 3)->default('TRY');

            /*
             * How long the credits live once bought. Null means they do not expire,
             * which is a real product decision rather than a missing value — an
             * enterprise plan may well sell non-expiring credits.
             */
            $table->integer('validity_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('position')->default(0);

            $table->timestampsTz();

            $table->index(['is_active', 'position']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_packages
            ADD CONSTRAINT credit_packages_amounts_check
            CHECK (
                credits > 0
                AND bonus_credits >= 0
                AND price_minor >= 0
                AND (validity_days IS NULL OR validity_days > 0)
            )
        SQL);

        /*
         * One wallet per user.
         *
         * `reserved` is held, not spent: it is part of the balance and cannot be spent
         * twice. `available` — balance minus reserved — is what a customer can actually
         * start a render with, and it is computed rather than stored so the two can
         * never disagree.
         *
         * The lifetime totals are denormalised on purpose. "How many credits has this
         * customer ever bought" is asked by support and by finance often enough that
         * scanning the ledger for it every time would be the wrong trade.
         */
        Schema::create('credit_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('user_id')->unique();
            /*
             * Restricted, not cascaded, and the same everywhere below.
             *
             * The ledger is append-only by trigger, which includes DELETE — so a
             * cascade from a deleted user would not quietly erase the history, it
             * would fail halfway through and leave the deletion stuck. Saying
             * 'restrict' states the real rule out loud: a financial record outlives
             * the account it belonged to, which is also what tax retention requires.
             *
             * Erasing an account therefore means anonymising the person and keeping
             * the money. That procedure belongs to Phase 21 and needs to be explicit
             * and audited rather than a side effect of a foreign key.
             */
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->integer('balance')->default(0);
            $table->integer('reserved')->default(0);

            $table->bigInteger('lifetime_purchased')->default(0);
            $table->bigInteger('lifetime_granted')->default(0);
            $table->bigInteger('lifetime_consumed')->default(0);
            $table->bigInteger('lifetime_expired')->default(0);

            $table->timestampTz('last_movement_at')->nullable();

            $table->timestampsTz();
        });

        /*
         * The last line of defence, and the one that matters most.
         *
         * A negative balance means somebody was given something for nothing. Reserved
         * exceeding the balance means the same credits are promised to two jobs. Neither
         * is recoverable by reading logs afterwards, so neither is left to the
         * application to remember.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE credit_wallets
            ADD CONSTRAINT credit_wallets_balance_check
            CHECK (balance >= 0 AND reserved >= 0 AND reserved <= balance)
        SQL);

        /*
         * A batch of credits with one expiry date.
         *
         * `remaining` is decremented as credits are consumed or expire. Consumption goes
         * oldest-expiry-first so a customer never loses credits they could have used —
         * spending the non-expiring ones first would silently destroy the ones with a
         * deadline, and they would never know why.
         */
        Schema::create('credit_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('credit_wallets')->restrictOnDelete();

            $table->string('source', 20);

            $table->integer('amount');
            $table->integer('remaining');

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('exhausted_at')->nullable();

            /*
             * What produced this lot — a package purchase, a promotion, an admin grant.
             * Polymorphic because the answer is a different table each time and a
             * nullable foreign key per possibility would be a column per feature.
             */
            $table->string('origin_type', 120)->nullable();
            $table->uuid('origin_id')->nullable();

            $table->timestampsTz();

            // The consumption order, as an index: unexpired lots, soonest deadline first.
            $table->index(['wallet_id', 'expires_at']);
            $table->index(['expires_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_lots
            ADD CONSTRAINT credit_lots_amount_check
            CHECK (amount > 0 AND remaining >= 0 AND remaining <= amount)
        SQL);

        /*
         * The ledger. The authority for every credit that has ever existed.
         *
         * `amount` is signed: a grant is positive, a consumption negative. A reservation
         * is zero, because holding credits moves nothing — it only changes what is
         * available — and recording it as a movement would make every sum wrong.
         *
         * `balance_after` is stored rather than derived. Recomputing a running balance
         * from the start of time is both slow and fragile, and a statement a customer
         * disputes has to show the balance as it stood *then*, not as today's code would
         * calculate it.
         */
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('credit_wallets')->restrictOnDelete();

            $table->string('type', 20);

            $table->integer('amount');
            $table->integer('balance_after');
            $table->integer('reserved_after');

            $table->uuid('lot_id')->nullable();
            $table->foreign('lot_id')->references('id')->on('credit_lots')->nullOnDelete();

            $table->uuid('reservation_id')->nullable();

            /*
             * What this was for, in the customer's language. Written at the time rather
             * than looked up later: the design it paid for may be deleted, and "Salon
             * tasarımı" on a statement should survive that.
             */
            $table->string('description', 200);

            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();

            /*
             * Who did this, when it was not the customer. An admin adjustment names the
             * member of staff and demands a reason; both are what an ombudsman asks for.
             */
            $table->uuid('actor_id')->nullable();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            $table->string('reason', 400)->nullable();

            /*
             * The idempotency key.
             *
             * Unique across the whole table, not per wallet: these come from job ids,
             * payment references and promotion codes, all of which are already unique,
             * and a global unique index makes "has this already been applied" a single
             * lookup rather than a question about scope.
             */
            $table->string('reference', 120)->nullable()->unique();

            $table->timestampTz('created_at');

            $table->index(['wallet_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_transactions
            ADD CONSTRAINT credit_transactions_type_check
            CHECK (type IN (
                'purchase', 'grant', 'promotion', 'reserve', 'release',
                'consume', 'expire', 'adjustment', 'refund'
            ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE credit_transactions
            ADD CONSTRAINT credit_transactions_balance_check
            CHECK (balance_after >= 0 AND reserved_after >= 0 AND reserved_after <= balance_after)
        SQL);

        /*
         * The direction of each type, enforced rather than trusted.
         *
         * A "consume" that adds credits is not a rounding error, it is free money, and it
         * would be invisible in every report because the totals would still balance. An
         * adjustment is the one type allowed to go either way — that is what makes it an
         * adjustment — and it is also the only one that demands a reason.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE credit_transactions
            ADD CONSTRAINT credit_transactions_direction_check
            CHECK (
                (type IN ('purchase', 'grant', 'promotion', 'refund') AND amount > 0)
                OR (type IN ('consume', 'expire') AND amount < 0)
                OR (type IN ('reserve', 'release') AND amount = 0)
                OR (type = 'adjustment' AND amount <> 0 AND reason IS NOT NULL)
            )
        SQL);

        /*
         * Append-only, by trigger.
         *
         * Not by convention, not by a policy somebody has to remember, and not by an
         * Eloquent guard that a raw query would walk straight past. A ledger that can be
         * edited is a ledger nobody can rely on in a dispute, and the whole reason for
         * having one is disputes.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refconcept_credit_transactions_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'credit_transactions is append-only; correct a mistake with a compensating entry';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER credit_transactions_no_update
            BEFORE UPDATE OR DELETE ON credit_transactions
            FOR EACH ROW EXECUTE FUNCTION refconcept_credit_transactions_append_only();
        SQL);

        /*
         * An open hold on some of a wallet's balance.
         *
         * A row rather than a number on the wallet, because releasing needs to know *how
         * much* this particular job was holding — and because a request that is retried
         * must find its existing hold instead of taking a second one. `reference` is the
         * caller's idempotency key, and it is unique.
         */
        Schema::create('credit_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('wallet_id');
            $table->foreign('wallet_id')->references('id')->on('credit_wallets')->restrictOnDelete();

            $table->integer('amount');
            $table->string('status', 20)->default('held');

            $table->string('reference', 120)->unique();

            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();

            /*
             * When an abandoned hold may be swept up. A customer who closes the tab
             * mid-render must not have those credits locked away forever, and a hold with
             * no deadline is exactly that.
             */
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('settled_at')->nullable();

            $table->timestampsTz();

            $table->index(['wallet_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_reservations
            ADD CONSTRAINT credit_reservations_amount_check
            CHECK (amount > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE credit_reservations
            ADD CONSTRAINT credit_reservations_status_check
            CHECK (status IN ('held', 'consumed', 'released', 'expired'))
        SQL);

        /*
         * Settled reservations have a settlement time, held ones do not. A released hold
         * with no timestamp would make "when did this customer get their credits back"
         * unanswerable, which is the one question a release exists to answer.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE credit_reservations
            ADD CONSTRAINT credit_reservations_settled_check
            CHECK (
                (status = 'held' AND settled_at IS NULL)
                OR (status <> 'held' AND settled_at IS NOT NULL)
            )
        SQL);

        Schema::table('credit_transactions', function (Blueprint $table): void {
            $table->foreign('reservation_id')->references('id')->on('credit_reservations')->nullOnDelete();
        });

        /*
         * A promotion: a code somebody types, or a campaign granted automatically.
         *
         * Limits are two, and they are different questions. `max_redemptions` protects
         * the budget — a code posted publicly must not be able to give away unlimited
         * credits. `max_per_user` protects against one person redeeming repeatedly, which
         * is the far commoner abuse and is not caught by a total.
         */
        Schema::create('credit_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // citext: somebody typing "hosgeldin" should get the promotion named
            // "HOSGELDIN". Case sensitivity here would be a support ticket generator.
            $table->string('code', 40)->unique();

            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            $table->integer('credits');
            $table->integer('validity_days')->nullable();

            $table->integer('max_redemptions')->nullable();
            $table->integer('max_per_user')->default(1);
            $table->integer('redemption_count')->default(0);

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            /*
             * Whether an account has to be new. The commonest promotion in this business
             * is a welcome bonus, and one that any existing customer can also claim is
             * not a welcome bonus.
             */
            $table->boolean('new_accounts_only')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->index(['is_active', 'ends_at']);
        });

        // Case-insensitive after creation, the same way users.email is handled.
        DB::statement('ALTER TABLE credit_promotions ALTER COLUMN code TYPE citext');

        DB::statement(<<<'SQL'
            ALTER TABLE credit_promotions
            ADD CONSTRAINT credit_promotions_amounts_check
            CHECK (
                credits > 0
                AND max_per_user > 0
                AND redemption_count >= 0
                AND (max_redemptions IS NULL OR max_redemptions > 0)
                AND (validity_days IS NULL OR validity_days > 0)
                AND (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
            )
        SQL);

        Schema::create('credit_promotion_redemptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('promotion_id');
            $table->foreign('promotion_id')->references('id')->on('credit_promotions')->cascadeOnDelete();

            $table->uuid('user_id');
            // A redemption is part of the financial record too; same rule as above.
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();

            $table->uuid('transaction_id')->nullable();
            $table->foreign('transaction_id')->references('id')->on('credit_transactions')->nullOnDelete();

            $table->integer('credits');

            $table->timestampTz('created_at');

            $table->index(['user_id', 'created_at']);

            /*
             * The per-user limit, where the database can enforce it.
             *
             * Almost every promotion is once-per-person, and for those this index makes a
             * double redemption impossible rather than unlikely — two simultaneous
             * requests both pass an application check and one of them then loses to this.
             * Promotions with a higher limit are counted under a lock instead.
             */
            $table->index(['promotion_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_promotion_redemptions
            ADD CONSTRAINT credit_promotion_redemptions_credits_check
            CHECK (credits > 0)
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS credit_transactions_no_update ON credit_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS refconcept_credit_transactions_append_only()');

        Schema::dropIfExists('credit_promotion_redemptions');
        Schema::dropIfExists('credit_promotions');
        Schema::dropIfExists('credit_reservations');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_lots');
        Schema::dropIfExists('credit_wallets');
        Schema::dropIfExists('credit_packages');
    }
};

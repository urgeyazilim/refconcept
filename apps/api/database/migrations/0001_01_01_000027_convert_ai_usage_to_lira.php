<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns every recorded AI cost into lira.
 *
 * The platform reports in lira and nothing a person sees is in another currency. AI usage
 * was the exception: providers quote dollars per million tokens, and the figure was stored
 * exactly as quoted with `USD` next to it. Every other money column in the database is
 * lira, so a spend total sat alongside an order total in a different unit with nothing
 * saying so.
 *
 * The rows are converted, not relabelled. Writing `TRY` over a dollar figure would make the
 * number wrong by the whole exchange rate and wrong silently — nothing else in the system
 * would ever disagree with it.
 *
 * The rate comes from configuration at the moment this runs, which is the honest answer for
 * a backfill: there is no record of what the rate was on the day each row was written, and
 * inventing a per-row rate would be worse than applying one and saying so.
 *
 * Deliberately **not** reversible. Dividing back would not restore the original integers —
 * the rounding is lossy — and a `down()` that returns different numbers than it was given
 * is a `down()` that quietly corrupts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rate = (float) config('refconcept.fx.usd_try', 34.0);

        if ($rate <= 0.0) {
            // An unusable rate leaves the rows alone rather than zeroing them. A spend
            // report reading zero looks like a quiet month, and nobody investigates one.
            return;
        }

        DB::table('ai_usage')
            ->where('currency', 'USD')
            ->update([
                'cost_micros' => DB::raw('ROUND(cost_micros * '.$rate.')::bigint'),
                'currency' => 'TRY',
            ]);

        // The per-request cost ceiling on a route is quoted in the same micros, so it moves
        // with them. A ceiling left in dollars would pause a task at a thirty-fourth of the
        // limit somebody set.
        if (Schema::hasColumn('ai_task_routes', 'max_cost_micros')) {
            DB::table('ai_task_routes')
                ->where('max_cost_micros', '>', 0)
                ->update(['max_cost_micros' => DB::raw('ROUND(max_cost_micros * '.$rate.')::bigint')]);
        }
    }

    public function down(): void
    {
        // Intentionally empty. See the note above: the conversion is lossy, and a rollback
        // that returns different numbers than it received is worse than no rollback.
    }
};

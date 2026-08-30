<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a placement ask for six of the same chair instead of six different ones.
 *
 * Everything downstream was built for one of a thing. A programme option asking for six
 * dining chairs became six separate placements, and the shopping list refuses to suggest
 * the same product twice — deliberately, because two placements that both want "a lamp"
 * should produce two different lamps. So a six-person dining table came back with one
 * chair and five groups saying nobody sells them. A pair of nightstands came back as one
 * nightstand. Two armchairs flanking a fireplace came back mismatched, or half missing.
 *
 * The rule that caused it is still right; what was missing is the distinction it needs.
 * Some quantities are a *set* — six matching dining chairs, a pair of nightstands, two
 * armchairs either side of a window — and some are genuinely different things that happen
 * to share a category: "orta ve yan sehpa" is two tables and they should not match.
 *
 * `identical` is that distinction, on the option's category rather than inferred from the
 * quantity, because the quantity cannot tell you which kind it is. A set becomes one
 * placement carrying its count; the rest still become separate placements that each find
 * their own product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programme_option_categories', function (Blueprint $table): void {
            /*
             * Defaults to true, which is the safer wrong answer.
             *
             * A set treated as separate items produces a table with one chair — visibly
             * broken and confusing. Separate items treated as a set produces two identical
             * side tables — a defensible design somebody might even have chosen. When a new
             * option's author forgets to think about it, the second failure is the one to
             * have.
             */
            $table->boolean('identical')->default(true);
        });

        /*
         * The exceptions, set here as well as in the seeder.
         *
         * The seeder rewrites these rows on every deploy and will carry the values from
         * now on; this is for the databases that already have the rows and will not see a
         * seeder run before somebody opens the wizard.
         */
        $mixed = DB::table('programme_options as o')
            ->join('programme_questions as q', 'q.id', '=', 'o.question_id')
            ->whereIn('o.code', ['center-and-side', 'both'])
            ->pluck('o.id');

        DB::table('programme_option_categories')
            ->whereIn('option_id', $mixed)
            ->update(['identical' => false]);
    }

    public function down(): void
    {
        Schema::table('programme_option_categories', function (Blueprint $table): void {
            $table->dropColumn('identical');
        });
    }
};

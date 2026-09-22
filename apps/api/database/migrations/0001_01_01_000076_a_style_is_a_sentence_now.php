<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Modern ve çağdaş; açık gri, sıcak meşe ve kontrollü mat siyah vurgular" — sixty-nine
 * characters into a column that holds sixty.
 *
 * The plan behind it was the best one this system has produced. Ninety seconds, eleven
 * placements, every width in millimetres and every one of them right: 2200 for the sofa,
 * 850 for the armchair, 1800 for the television unit, 400 for the floor lamp. A focal point
 * on the existing television wall, the sofa held forty centimetres off it, curtains hung to
 * the ceiling and kept clear of the radiators. It was thrown away on the way to the table,
 * and the customer was told "Görsel üretilemedi: beklenmeyen bir hata."
 *
 * The column was sixty characters because a style used to be a word — "modern", "iskandinav"
 * — chosen from a list. The engine that plans rooms now writes prose everywhere, and this is
 * the second column to be surprised by that in two days; the first took a whole room reading
 * down with it. A style is a description, and a description has no business being measured
 * in characters.
 *
 * Text, then. The width is fitted on the way in as well, because a column with no limit is
 * an invitation for a model having a bad day to write a paragraph into it — see
 * {@see DesignGenerationPipeline}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_plans', function (Blueprint $table): void {
            $table->text('style')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Truncated rather than refused. Going back to sixty characters with a longer style
         * already stored would fail the migration itself, which turns a rollback into an
         * outage — and the styles that predate this change all fit.
         */
        DB::statement(
            'update design_plans set style = left(style, 60) where length(style) > 60'
        );

        Schema::table('design_plans', function (Blueprint $table): void {
            $table->string('style', 60)->nullable()->change();
        });
    }
};

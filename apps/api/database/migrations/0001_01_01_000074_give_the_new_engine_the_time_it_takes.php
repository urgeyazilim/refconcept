<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ninety seconds was a Gemini number.
 *
 * The reading and the plan were given ninety seconds an attempt and three attempts, which was
 * generous when a reading took twenty-nine seconds. GPT-6 Astra takes fifty-two to seventy for
 * the same four photographs, and the plan came in at sixty-seven — so an attempt that would
 * have finished was being cut off, retried, and cut off again. One reading took a hundred and
 * eighty-six seconds across three attempts to produce the answer the first one was most of the
 * way through.
 *
 * Taking the furniture out moved too, from an image model that did it in sixteen seconds to
 * one that takes ninety-nine, and its two-minute limit was about to start cutting that off as
 * well.
 *
 * A cut-off attempt is not free. It is a call that was made, thought about and paid for, and
 * then thrown away a moment before it was worth anything.
 *
 * So: three minutes an attempt, and two attempts rather than three. The second number matters
 * as much as the first — a call that ran out of time at three minutes is unlikely to be
 * quicker for being asked again, and a third attempt is mostly a third bill. Two is one retry
 * for the genuinely unlucky and a bounded wait for everybody else.
 */
return new class extends Migration
{
    /** The tasks a customer waits on, and where the new engine actually lands. */
    private const SLOW = ['room_analysis', 'design_plan', 'render_check', 'product_match_rerank', 'room_clear'];

    public function up(): void
    {
        DB::table('ai_task_routes')
            ->whereIn('task', self::SLOW)
            ->update([
                'timeout_seconds' => 180,
                // Left alone where somebody has already decided on one attempt: render_check
                // is deliberately a single look and doubling it would double a bill nobody
                // asked to double.
                'max_attempts' => DB::raw('least(max_attempts, 2)'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('ai_task_routes')
            ->whereIn('task', self::SLOW)
            ->update(['timeout_seconds' => 90, 'updated_at' => now()]);
    }
};

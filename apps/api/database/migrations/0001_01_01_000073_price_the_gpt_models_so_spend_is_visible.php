<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A model with no rate reports every call as free.
 *
 * The routes moved to GPT and the first real reading came back saying it cost $0.0000, which
 * is not a discount — it is a model nobody priced. Cost is worked out from a rate row, and
 * without one every job on the new engines is silently recorded as free. A platform that
 * cannot say what it spent is a platform that finds out at the end of the month.
 *
 * Published list prices, in micros per million tokens:
 *
 *   gpt-6-astra              $10 in / $50 out
 *   gpt-5.5                  $1.75 in / $14 out
 *   text-embedding-3-large   $0.13 in
 *
 * Astra is eight times Gemini 2.5 Pro on input and five times on output, which is worth
 * knowing rather than discovering. It is also the model the product owner asked for after
 * trying both, and on the first real room it found the frosted-glass door, the french
 * balcony, the window under the air conditioner, both radiators and the cove lighting where
 * the previous engine found a door and one window on the wrong wall. That is what is being
 * paid for.
 */
return new class extends Migration
{
    /** @var array<string, array{in: int, out: int}> */
    private const RATES = [
        'gpt-6-astra' => ['in' => 10_000_000, 'out' => 50_000_000],
        'gpt-5.5' => ['in' => 1_750_000, 'out' => 14_000_000],
        'text-embedding-3-large' => ['in' => 130_000, 'out' => 0],
    ];

    public function up(): void
    {
        foreach (self::RATES as $code => $rate) {
            $model = DB::table('ai_models')->where('code', $code)->first();

            if ($model === null) {
                continue;
            }

            $exists = DB::table('ai_cost_rates')
                ->where('model_id', $model->id)
                ->whereNull('effective_to')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('ai_cost_rates')->insert([
                'id' => (string) Str::uuid7(),
                'model_id' => $model->id,
                'currency' => 'USD',
                'input_micros_per_million_tokens' => $rate['in'],
                'output_micros_per_million_tokens' => $rate['out'],
                'micros_per_image' => 0,
                'micros_per_request' => 0,
                'effective_from' => now(),
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('ai_models')->whereIn('code', array_keys(self::RATES))->pluck('id');

        DB::table('ai_cost_rates')->whereIn('model_id', $ids)->delete();
    }
};

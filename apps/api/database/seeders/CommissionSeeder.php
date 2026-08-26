<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Finance\Models\CommissionRule;
use Illuminate\Database\Seeder;

/**
 * The one rule the resolver must never be without.
 *
 * A platform default has to exist before the first sale, because a line with no rate is a
 * line whose commission is a guess. The resolver has its own last-resort constant for the
 * same reason, but a configured row is what an operator can actually see and change.
 */
final class CommissionSeeder extends Seeder
{
    public function run(): void
    {
        $existing = CommissionRule::query()->where('scope', 'platform')->where('is_active', true)->first();

        if ($existing !== null) {
            return;
        }

        CommissionRule::query()->create([
            'scope' => 'platform',
            'rate_bps' => (int) config('refconcept.commission.platform_default_bps', 1200),
            'priority' => 900,
            'label' => 'Platform varsayılan komisyonu',
        ]);
    }
}

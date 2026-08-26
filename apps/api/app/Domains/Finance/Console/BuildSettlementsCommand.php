<?php

declare(strict_types=1);

namespace App\Domains\Finance\Console;

use App\Domains\Finance\Services\SettlementService;
use Illuminate\Console\Command;

/**
 * Prepares a payout draft for every seller with something eligible.
 *
 * Only a draft. Nothing is posted to the ledger and no money moves — the point is that a
 * person opens the finance screen in the morning and finds the arithmetic already done,
 * then decides. A schedule that approved its own payouts would be a schedule that pays a
 * suspended seller at 03:00 on a Sunday.
 */
final class BuildSettlementsCommand extends Command
{
    protected $signature = 'refconcept:build-settlements';

    protected $description = 'Hakedişe hazır siparişler için taslak hakediş oluşturur.';

    public function handle(SettlementService $settlements): int
    {
        $built = $settlements->buildAll();

        $this->info($built === []
            ? 'Hakedişe hazır sipariş yok.'
            : sprintf('%d hakediş taslağı hazırlandı.', count($built)));

        return self::SUCCESS;
    }
}

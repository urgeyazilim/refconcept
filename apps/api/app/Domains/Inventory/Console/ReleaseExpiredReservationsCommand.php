<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Console;

use App\Domains\Inventory\Services\InventoryLedger;
use Illuminate\Console\Command;

/**
 * Gives back stock that abandoned baskets are still holding.
 *
 * {@see InventoryLedger::reserve()} already clears stale holds on the row it is about
 * to reserve, so a customer never waits for this command to run before they can buy.
 * What this catches is the rest: stock held against products nobody has tried to buy
 * since, which would otherwise sit reserved and invisible until somebody did.
 */
final class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'refconcept:release-expired-reservations';

    protected $description = 'Süresi dolmuş stok rezervasyonlarını serbest bırakır.';

    public function handle(InventoryLedger $ledger): int
    {
        $released = $ledger->releaseExpired();

        $this->info($released === 0
            ? 'Süresi dolmuş rezervasyon yok.'
            : sprintf('%d rezervasyon serbest bırakıldı.', $released));

        return self::SUCCESS;
    }
}

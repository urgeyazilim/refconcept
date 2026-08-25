<?php

declare(strict_types=1);

namespace App\Domains\Payments\Console;

use App\Domains\Payments\Services\BankTransferService;
use Illuminate\Console\Command;

/**
 * Closes transfers nobody paid and gives the stock back.
 *
 * The counterpart to the long hold: stock is held for two days against a transfer that may
 * never arrive, so the moment the window closes it has to go back on sale. Two days is the
 * price of the payment method; two days and one hour is somebody else being told "sold
 * out" for nothing.
 */
final class ExpireBankTransfersCommand extends Command
{
    protected $signature = 'refconcept:expire-bank-transfers';

    protected $description = 'Süresi dolmuş havale kayıtlarını kapatır ve stoğu serbest bırakır.';

    public function handle(BankTransferService $transfers): int
    {
        $closed = $transfers->expireOverdue();

        $this->info($closed === 0
            ? 'Süresi dolmuş havale yok.'
            : sprintf('%d havale kaydı kapatıldı.', $closed));

        return self::SUCCESS;
    }
}

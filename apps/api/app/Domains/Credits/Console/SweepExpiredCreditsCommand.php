<?php

declare(strict_types=1);

namespace App\Domains\Credits\Console;

use App\Domains\Credits\Services\CreditExpirySweeper;
use Illuminate\Console\Command;

/**
 * Expires credits that reached their date and frees holds nobody came back for.
 *
 * Scheduled hourly rather than nightly. A hold left over from an abandoned render is
 * credits a customer cannot spend while their screen tells them they can, and waiting
 * until midnight to give them back turns a two-minute annoyance into a support ticket.
 */
final class SweepExpiredCreditsCommand extends Command
{
    protected $signature = 'refconcept:sweep-credits {--chunk=500 : How many rows to process at a time}';

    protected $description = 'Expire dated credit lots and release abandoned reservations';

    public function handle(CreditExpirySweeper $sweeper): int
    {
        $result = $sweeper->sweep((int) $this->option('chunk'));

        $this->info(sprintf(
            '%d bloke serbest bırakıldı, %d partiden toplam %d kredinin süresi doldu.',
            $result['reservations'],
            $result['lots'],
            $result['credits'],
        ));

        return self::SUCCESS;
    }
}

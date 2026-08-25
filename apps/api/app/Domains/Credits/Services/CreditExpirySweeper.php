<?php

declare(strict_types=1);

namespace App\Domains\Credits\Services;

use App\Domains\Credits\Enums\ReservationStatus;
use App\Domains\Credits\Models\CreditLot;
use App\Domains\Credits\Models\CreditReservation;

/**
 * Housekeeping for two things that rot if nobody sweeps them.
 *
 * **Lots that reached their date.** Credits with a deadline have to actually stop being
 * spendable, and a balance that quietly includes expired credits is a promise the system
 * will break at the till.
 *
 * **Holds nobody came back for.** A customer who closes the tab mid-render leaves credits
 * locked away with no job left to settle them. Without this they stay locked forever,
 * and the symptom is somebody unable to spend a balance their screen says they have —
 * one of the hardest complaints to diagnose from the outside.
 *
 * Chunked and one transaction per row on purpose. This runs over every wallet on the
 * platform, and holding one long lock while doing it would stall every render in progress
 * at exactly the hour it is scheduled.
 */
final class CreditExpirySweeper
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Expires lots that have passed their date.
     *
     * Abandoned holds are released *first*, deliberately. A lot can only expire what is
     * not currently held, so sweeping holds beforehand means credits stuck behind a dead
     * reservation expire in the same run rather than surviving another day for no
     * defensible reason.
     *
     * @return array{lots: int, credits: int, reservations: int}
     */
    public function sweep(int $chunk = 500): array
    {
        $reservations = $this->releaseAbandonedHolds($chunk);

        $lots = 0;
        $credits = 0;

        CreditLot::query()
            ->expiring(now())
            ->orderBy('id')
            ->chunkById($chunk, function ($batch) use (&$lots, &$credits): void {
                foreach ($batch as $lot) {
                    $transaction = $this->ledger->expireLot($lot);

                    if ($transaction === null) {
                        continue;
                    }

                    $lots++;
                    $credits += abs($transaction->amount);
                }
            });

        return ['lots' => $lots, 'credits' => $credits, 'reservations' => $reservations];
    }

    /**
     * Returns credits held by work that never finished.
     *
     * Recorded as `expired` rather than `released`, because the two mean different things
     * to whoever reads the record later: a release is a system that finished its job
     * correctly, and an expiry is a request that vanished. Collapsing them would hide a
     * slow leak that only shows up as a rising count of dead holds.
     */
    public function releaseAbandonedHolds(int $chunk = 500): int
    {
        $released = 0;

        CreditReservation::query()
            ->abandoned()
            ->orderBy('id')
            ->chunkById($chunk, function ($batch) use (&$released): void {
                foreach ($batch as $reservation) {
                    $settled = $this->ledger->release(
                        $reservation,
                        'Tamamlanmayan işlem için bloke çözüldü',
                        ReservationStatus::Expired,
                    );

                    if ($settled->status === ReservationStatus::Expired) {
                        $released++;
                    }
                }
            });

        return $released;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Finance\Console;

use App\Domains\Finance\Services\PaymentReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Compares what the provider says with what the books say, for a window.
 *
 * Run daily. The exit code is the point: a non-zero exit is something a scheduler can
 * alert on, and a reconciliation nobody is alerted about is a report nobody reads.
 *
 *   php artisan refconcept:reconcile-payments
 *   php artisan refconcept:reconcile-payments --days=7
 *
 * Nothing is corrected here. A mismatch means two systems disagree about money, and
 * guessing which one is right is how a small discrepancy becomes a large one — so the
 * command reports and stops.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'refconcept:reconcile-payments
        {--days=1 : How many days back to compare}';

    protected $description = 'Compare provider payment records against the ledger';

    public function handle(PaymentReconciliation $reconciliation): int
    {
        $days = max(1, (int) $this->option('days'));

        $to = Carbon::now();
        $from = $to->copy()->subDays($days)->startOfDay();

        $report = $reconciliation->forPeriod($from, $to);

        $this->line(sprintf('Dönem: %s → %s', $report['from'], $report['to']));
        $this->line(sprintf(
            'Sağlayıcı: %s tahsil, %s iade, net %s',
            $this->money($report['provider']['captured_minor']),
            $this->money($report['provider']['refunded_minor']),
            $this->money($report['provider']['net_minor']),
        ));
        $this->line(sprintf('Defter kasa hareketi: %s', $this->money($report['ledger']['cash_minor'])));

        if ($report['ledger']['is_balanced'] !== true) {
            // Said first and said loudly. If the journal does not balance, every figure
            // above it is a number rather than an amount.
            $this->error('Defter denk değil. Mutabakattan önce bu çözülmeli.');
        }

        if ($report['is_reconciled'] === true) {
            $this->info('✔ Mutabık.');

            return self::SUCCESS;
        }

        foreach ($report['findings'] as $finding) {
            $line = sprintf(
                '[%s] %s%s',
                $finding['kind'],
                $finding['message'],
                isset($finding['reference']) ? ' ('.$finding['reference'].')' : '',
            );

            if (($finding['severity'] ?? 'warning') === 'critical') {
                $this->error($line);
            } else {
                $this->warn($line);
            }
        }

        /*
         * A warning is not a failure.
         *
         * A bank transfer waiting for a customer is normal and would otherwise page
         * somebody every night until they stopped reading the alerts — which is worse than
         * not sending them.
         */
        $critical = array_filter(
            $report['findings'],
            static fn (array $finding): bool => ($finding['severity'] ?? 'warning') === 'critical',
        );

        return $critical === [] ? self::SUCCESS : self::FAILURE;
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', '.').' ₺';
    }
}

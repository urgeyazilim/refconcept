<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Enums\LedgerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Does the money we say we took match the money the provider says they took?
 *
 * Two independent records exist for every payment: the provider's transaction log, and our
 * double-entry journal. Each is internally consistent — the ledger balances by
 * construction, and the provider's own books certainly balance — which is precisely why
 * neither can be checked against itself. Reconciliation is the only thing that catches a
 * capture that never posted, a webhook processed twice, or a refund the books recorded and
 * the bank never sent.
 *
 * The failures this looks for are the quiet ones. A payment that errors loudly is fixed the
 * same day; a payment that succeeds at the provider and posts nothing here is invisible
 * until a seller asks where their money is, months later, with a settlement already paid on
 * figures that were wrong.
 *
 * Nothing is corrected automatically. A mismatch means two systems disagree about money,
 * and guessing which one is right is how a small discrepancy becomes a large one.
 */
final class PaymentReconciliation
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * A day's worth of comparison, or any window.
     *
     * @return array<string, mixed>
     */
    public function forPeriod(Carbon $from, Carbon $to): array
    {
        // 'sale' is what the processor records for a payment taken: authorisation and
        // capture in one step, which is what every gateway here does today.
        $captured = $this->providerTotal('sale', $from, $to);
        $refunded = $this->providerTotal('refund', $from, $to);

        $posted = $this->ledgerTotal(LedgerAccount::CashProvider, $from, $to);

        $findings = [];

        /*
         * The headline comparison: everything the provider captured, less everything they
         * refunded, against what the cash account moved. Refunds are subtracted rather than
         * compared separately because they hit the same account in the other direction —
         * comparing them apart would report a discrepancy on every day with a refund in it.
         */
        $expected = $captured - $refunded;

        if ($expected !== $posted) {
            $findings[] = [
                'kind' => 'cash_mismatch',
                'severity' => 'critical',
                'message' => sprintf(
                    'Sağlayıcı %d kuruş tahsil etti, defter %d kuruş gösteriyor. Fark: %d kuruş.',
                    $expected,
                    $posted,
                    $expected - $posted,
                ),
            ];
        }

        foreach ($this->unpostedCaptures($from, $to) as $row) {
            $findings[] = [
                'kind' => 'captured_not_posted',
                'severity' => 'critical',
                'reference' => $row->reference,
                'amount_minor' => (int) $row->amount_minor,
                // The worst case in the list: the customer has paid and the platform's
                // books do not know, so nothing downstream — commission, payout, tax —
                // will ever include it.
                'message' => 'Tahsil edildi ama deftere işlenmedi.',
            ];
        }

        foreach ($this->duplicateExternalIds($from, $to) as $row) {
            $findings[] = [
                'kind' => 'duplicate_transaction',
                'severity' => 'critical',
                'reference' => $row->external_id,
                'count' => (int) $row->total,
                // A webhook delivered twice and processed twice. The idempotency key is
                // supposed to make this impossible, which is exactly why it is worth
                // checking rather than assuming.
                'message' => 'Aynı sağlayıcı işlemi birden fazla kez kaydedilmiş.',
            ];
        }

        foreach ($this->stuckPending($from) as $row) {
            $findings[] = [
                'kind' => 'stuck_pending',
                'severity' => 'warning',
                'reference' => $row->reference,
                'amount_minor' => (int) $row->amount_minor,
                // Not necessarily wrong — a bank transfer legitimately waits — but a card
                // payment pending for a day is a payment whose outcome nobody chased.
                'message' => 'Uzun süredir beklemede; sağlayıcıdan sorgulanmalı.',
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'provider' => [
                'captured_minor' => $captured,
                'refunded_minor' => $refunded,
                'net_minor' => $expected,
            ],
            'ledger' => [
                'cash_minor' => $posted,
                'is_balanced' => $this->ledger->isBalanced(),
            ],
            'is_reconciled' => $findings === [],
            'findings' => $findings,
        ];
    }

    // --- internals -----------------------------------------------------------

    private function providerTotal(string $type, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('payment_transactions')
            ->where('type', $type)
            ->where('status', 'succeeded')
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('amount_minor');
    }

    private function ledgerTotal(LedgerAccount $account, Carbon $from, Carbon $to): int
    {
        /*
         * Debits less credits on the cash account. Taken from the lines rather than from
         * the balance projection on purpose: the projection is derived, and a
         * reconciliation that reads a derived figure is checking one system against a
         * summary of itself.
         */
        return (int) DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
            ->where('ledger_accounts.code', $account->value)
            ->whereBetween('ledger_entries.posted_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(ledger_lines.debit_minor) - SUM(ledger_lines.credit_minor), 0) AS net')
            ->value('net');
    }

    /**
     * Successful captures with no journal entry naming them.
     *
     * @return list<object>
     */
    private function unpostedCaptures(Carbon $from, Carbon $to): array
    {
        /*
         * The chain from a captured payment to its journal entry:
         *
         *   payment_transactions → payment_intents → checkout_sessions → orders → entries
         *
         * Followed rather than short-circuited, because each hop is a place the link can
         * be missing and the whole point is to find out which. A capture whose intent has
         * no order is a different failure from an order with no journal entry, and lumping
         * them together would leave whoever investigates with nowhere to start.
         *
         * Credit purchases are excluded: they post against credit revenue rather than
         * against an order, so an order-shaped test would report every one of them.
         */
        return DB::table('payment_transactions as t')
            ->join('payment_intents as i', 'i.id', '=', 't.payment_intent_id')
            ->join('checkout_sessions as s', 's.id', '=', 'i.checkout_session_id')
            ->where('t.type', 'sale')
            ->where('t.status', 'succeeded')
            ->where('s.purpose', 'cart')
            ->whereBetween('t.occurred_at', [$from, $to])
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('orders as o')
                    ->join('ledger_entries as e', function ($join): void {
                        $join->on('e.reference_id', '=', DB::raw('o.id::text'))
                            ->where('e.reference_type', '=', 'order');
                    })
                    ->whereColumn('o.checkout_session_id', 's.id');
            })
            ->select('t.payment_intent_id as reference', 't.amount_minor')
            ->get()
            ->all();
    }

    /**
     * @return list<object>
     */
    private function duplicateExternalIds(Carbon $from, Carbon $to): array
    {
        return DB::table('payment_transactions')
            ->whereNotNull('external_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->select('external_id', DB::raw('count(*) as total'))
            ->groupBy('external_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->all();
    }

    /**
     * Card payments still pending well after they should have resolved.
     *
     * @return list<object>
     */
    private function stuckPending(Carbon $from): array
    {
        return DB::table('payment_transactions')
            ->where('status', 'pending')
            ->where('occurred_at', '<', $from->copy()->subDay())
            ->select('payment_intent_id as reference', 'amount_minor')
            ->limit(50)
            ->get()
            ->all();
    }
}

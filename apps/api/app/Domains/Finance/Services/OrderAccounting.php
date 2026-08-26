<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turning an order into journal entries.
 *
 * One posting per order, in one entry, because the money arrived once:
 *
 *   debit   ASSET:CASH_PROVIDER        the whole amount the customer paid
 *   credit  LIABILITY:SELLER_PAYABLE   each seller's share, less commission
 *   credit  REVENUE:COMMISSION         what the platform keeps
 *
 * The shape is the point. A marketplace's cash is mostly a **liability**: it is holding
 * money it does not own, and the only part that is revenue is the commission. Posting the
 * whole amount as income and the payouts as expenses would balance perfectly and describe
 * a completely different business — one that is enormously profitable right up until it
 * pays its sellers.
 *
 * Cancelling a seller's part reverses only that seller's share, by its own entry. The
 * original stays visible, which is the rule about never rewriting historical finance.
 */
final class OrderAccounting
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Posts the journal for a paid order.
     *
     * Idempotent through the ledger's own key: a payment confirmed four times posts once.
     */
    public function recordSale(Order $order, ?User $actor = null): ?LedgerEntry
    {
        $order->loadMissing('sellerOrders');

        if ($order->sellerOrders->isEmpty()) {
            return null;
        }

        $lines = [JournalLine::debit(
            LedgerAccount::CashProvider,
            $order->grand_total_minor,
            memo: 'Sipariş '.$order->order_number,
        )];

        $commission = 0;

        foreach ($order->sellerOrders as $sellerOrder) {
            $payable = $sellerOrder->payableMinor();

            if ($payable > 0) {
                $lines[] = JournalLine::credit(
                    LedgerAccount::SellerPayable,
                    $payable,
                    (string) $sellerOrder->seller_id,
                    $sellerOrder->seller_order_number,
                );
            }

            if ($sellerOrder->commission_minor > 0) {
                /*
                 * One commission line per seller rather than one summed line.
                 *
                 * They all land in the same revenue account — commission is the platform's
                 * income, not a per-seller balance — but the line keeps the seller on it,
                 * which is what makes "what did we earn from this shop" a query rather than
                 * a reconstruction from order tables.
                 */
                $lines[] = JournalLine::credit(
                    LedgerAccount::Commission,
                    $sellerOrder->commission_minor,
                    (string) $sellerOrder->seller_id,
                    $sellerOrder->seller_order_number,
                );
            }

            $commission += $sellerOrder->commission_minor;
        }

        /*
         * Anything the order total does not account for.
         *
         * Shipping is zero today and will not be in Phase 17, and an entry that silently
         * fails to balance is worse than one that names the difference. Posting the
         * remainder to the clearing account keeps the books correct and leaves a visible
         * figure for whoever asks what it was.
         */
        $allocated = $commission + array_sum(array_map(
            static fn (SellerOrder $sellerOrder): int => $sellerOrder->payableMinor(),
            $order->sellerOrders->all(),
        ));

        $remainder = $order->grand_total_minor - $allocated;

        if ($remainder !== 0) {
            $lines[] = $remainder > 0
                ? JournalLine::credit(LedgerAccount::PaymentClearing, $remainder, memo: 'Dağıtılmamış tutar')
                : JournalLine::debit(LedgerAccount::PaymentClearing, -$remainder, memo: 'Fazla dağıtım');
        }

        return $this->ledger->post(
            type: 'order.sale',
            description: 'Sipariş '.$order->order_number.' tahsil edildi',
            lines: $lines,
            referenceType: 'order',
            referenceId: (string) $order->getKey(),
            idempotencyKey: 'order-sale:'.$order->getKey(),
            currency: $order->currency,
            actor: $actor,
        );
    }

    /**
     * Reverses one seller's share when their part is cancelled.
     *
     * Its own entry rather than a reversal of the whole sale: the other sellers' goods are
     * still on their way, and unwinding the customer's payment because one shop ran out
     * would be a much larger claim than the facts support.
     *
     * What the customer is refunded is a separate movement against the payment, made by
     * finance — money and goods travel on different timetables and pretending otherwise is
     * how one of them gets lost.
     */
    public function recordSellerCancellation(SellerOrder $sellerOrder, string $reason, ?User $actor = null): ?LedgerEntry
    {
        $payable = $sellerOrder->payableMinor();
        $commission = $sellerOrder->commission_minor;

        if ($payable === 0 && $commission === 0) {
            return null;
        }

        $lines = [];

        if ($payable > 0) {
            $lines[] = JournalLine::debit(
                LedgerAccount::SellerPayable,
                $payable,
                (string) $sellerOrder->seller_id,
                'İptal: '.$sellerOrder->seller_order_number,
            );
        }

        if ($commission > 0) {
            $lines[] = JournalLine::debit(
                LedgerAccount::Commission,
                $commission,
                memo: 'İptal: '.$sellerOrder->seller_order_number,
            );
        }

        // The customer's money is owed back rather than kept: the goods are not coming.
        $lines[] = JournalLine::credit(
            LedgerAccount::CustomerRefund,
            $payable + $commission,
            memo: 'İptal: '.$sellerOrder->seller_order_number,
        );

        return $this->ledger->post(
            type: 'order.seller_cancelled',
            description: mb_substr($reason, 0, 300),
            lines: $lines,
            referenceType: 'seller_order',
            referenceId: (string) $sellerOrder->getKey(),
            idempotencyKey: 'seller-order-cancel:'.$sellerOrder->getKey(),
            currency: $sellerOrder->currency,
            actor: $actor,
        );
    }

    /**
     * Recomputes a seller's balance from the journal.
     *
     * A projection, rebuilt rather than incremented. Incrementing means every write has to
     * be perfect forever; rebuilding means a bug in one posting is fixed by fixing the
     * posting, and the projection catches up on its own.
     *
     * `available` is what a settlement may take: delivered, past the hold, not already in
     * an open settlement. `pending` is the rest of what is owed.
     */
    public function rebuildBalance(string $sellerId, string $currency = 'TRY'): void
    {
        DB::transaction(function () use ($sellerId, $currency): void {
            $owed = $this->ledger->balanceOf(LedgerAccount::SellerPayable, $sellerId, $currency);

            $eligible = app(SettlementEligibility::class)->eligibleTotal($sellerId, $currency);
            $reserved = app(SettlementEligibility::class)->reservedTotal($sellerId, $currency);
            $paidOut = app(SettlementEligibility::class)->paidOutTotal($sellerId, $currency);

            $available = max(0, min($owed - $reserved, $eligible));

            DB::table('seller_balances')->updateOrInsert(
                ['seller_id' => $sellerId, 'currency' => $currency],
                [
                    'id' => DB::table('seller_balances')
                        ->where('seller_id', $sellerId)
                        ->where('currency', $currency)
                        ->value('id') ?? (string) Str::uuid7(),
                    'pending_minor' => max(0, $owed - $reserved - $available),
                    'available_minor' => $available,
                    'reserved_minor' => $reserved,
                    'paid_out_minor' => $paidOut,
                    'lifetime_commission_minor' => $this->ledger->sellerTotal(LedgerAccount::Commission, $sellerId, $currency),
                    'last_movement_at' => now(),
                    'updated_at' => now(),
                    'created_at' => DB::table('seller_balances')
                        ->where('seller_id', $sellerId)
                        ->where('currency', $currency)
                        ->value('created_at') ?? now(),
                ],
            );
        });
    }
}

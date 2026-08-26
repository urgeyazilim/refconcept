<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Services\JournalLine;
use App\Domains\Finance\Services\Ledger;
use App\Domains\Finance\Services\OrderAccounting;
use App\Domains\Fulfilment\Enums\RefundStatus;
use App\Domains\Fulfilment\Exceptions\FulfilmentRefused;
use App\Domains\Fulfilment\Models\Refund;
use App\Domains\Fulfilment\Models\ReturnItem;
use App\Domains\Fulfilment\Models\ReturnRequest;
use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SellerOrder;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Money going back.
 *
 * Two things happen and they are kept apart on purpose:
 *
 *  1. **The provider is asked to send the money.** This can fail — a payment too old to
 *     refund, an outage, a card that no longer exists — and failing is a state, not an
 *     exception to swallow. A `failed` refund is retryable, because the customer is owed
 *     the money either way.
 *
 *  2. **The books are corrected**, and only once the money has actually gone. The reversal
 *     is posted per share: the seller's payable comes down by their part and the platform's
 *     commission by its part, because a refund unwinds the sale rather than costing the
 *     platform the whole amount. Posting the whole refund against commission would make the
 *     platform pay for the seller's return.
 *
 * Nothing is ever edited to do this — the correction is a new, reversing journal entry, so
 * the sale and its unwinding both stay readable.
 */
final class RefundService
{
    public function __construct(
        private readonly PaymentProcessor $payments,
        private readonly Ledger $ledger,
        private readonly OrderAccounting $accounting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Opens the refund a completed return earns.
     *
     * Idempotent through the partial unique index: a return has at most one live refund,
     * so a retried completion returns the existing one rather than sending money twice.
     */
    public function openForReturn(ReturnRequest $return, ?User $actor = null): ?Refund
    {
        $return->loadMissing(['items', 'sellerOrder']);

        if ($return->approved_minor <= 0) {
            return null;
        }

        $existing = Refund::query()
            ->where('return_id', $return->getKey())
            ->whereIn('status', [
                RefundStatus::Pending->value,
                RefundStatus::Processing->value,
                RefundStatus::Succeeded->value,
            ])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $sellerOrder = $return->sellerOrder;

        if ($sellerOrder === null) {
            return null;
        }

        /*
         * The commission comes back too, at the rate that was charged.
         *
         * Reversing the sale means reversing all of it. Keeping the commission on returned
         * goods would mean the platform earns on a sale that did not happen, and the
         * seller funds it.
         */
        $commission = 0;

        foreach ($return->items as $item) {
            $commission += $this->commissionOn($item);
        }

        $refund = Refund::query()->create([
            'reference' => $this->reference(),
            'order_id' => $return->order_id,
            'seller_order_id' => $sellerOrder->getKey(),
            'return_id' => $return->getKey(),
            'payment_intent_id' => $return->order?->payment_intent_id,
            'currency' => $return->currency,
            'amount_minor' => $return->approved_minor,
            'seller_share_minor' => max(0, $return->approved_minor - $commission),
            'commission_share_minor' => $commission,
            'reason' => 'İade: '.$return->reference,
            'created_by' => $actor?->getKey(),
        ]);

        return $this->process($refund, $actor);
    }

    /**
     * A refund with no return behind it — goodwill, a mis-shipment, an operator's call.
     *
     * @throws FulfilmentRefused
     */
    public function openManual(
        Order $order,
        int $amountMinor,
        string $reason,
        User $actor,
        ?SellerOrder $sellerOrder = null,
    ): Refund {
        $remaining = $this->refundableMinor($order);

        if ($amountMinor <= 0 || $amountMinor > $remaining) {
            throw FulfilmentRefused::refundTooLarge($remaining);
        }

        /*
         * Split at the seller order's own rate when one is named, so the reversal is
         * still proportionate. A refund against the whole order with several sellers in
         * it is charged to the platform, because there is no honest way to decide whose
         * goods it was about.
         */
        $commission = $sellerOrder === null || $sellerOrder->total_minor === 0
            ? 0
            : (int) round($amountMinor * $sellerOrder->commission_minor / $sellerOrder->total_minor);

        $refund = Refund::query()->create([
            'reference' => $this->reference(),
            'order_id' => $order->getKey(),
            'seller_order_id' => $sellerOrder?->getKey(),
            'payment_intent_id' => $order->payment_intent_id,
            'currency' => $order->currency,
            'amount_minor' => $amountMinor,
            'seller_share_minor' => max(0, $amountMinor - $commission),
            'commission_share_minor' => $commission,
            'reason' => $reason,
            'created_by' => $actor->getKey(),
        ]);

        return $this->process($refund, $actor);
    }

    /**
     * Sends the money and, if it goes, corrects the books.
     *
     * The provider call is outside the ledger transaction deliberately: a posting that
     * rolled back because a bank was slow would leave the money gone and the books saying
     * otherwise.
     */
    public function process(Refund $refund, ?User $actor = null): Refund
    {
        if (! $refund->status->canTransitionTo(RefundStatus::Processing)) {
            throw FulfilmentRefused::refundNotRetryable($refund->status->label());
        }

        $refund->forceFill(['status' => RefundStatus::Processing])->save();

        $intent = $refund->payment;

        if ($intent === null) {
            // No payment to refund against — a bank transfer that was never captured, or
            // an order from before payments existed. Recorded as failed rather than
            // silently succeeding, so somebody looks at it.
            return $this->fail($refund, 'Ödeme kaydı bulunamadı.');
        }

        /*
         * A key per attempt, not per refund.
         *
         * The unique index on a payment transaction's operation key means a retry with the
         * same key could not be recorded at all — and a retry is the whole point of a
         * failed refund being a state. The refund's own status and row lock are what stop
         * two attempts running at once.
         */
        $attemptKey = 'refund:'.$refund->getKey().':'.Str::lower((string) Str::ulid());

        try {
            $this->payments->refund(
                $intent,
                $refund->amount_minor,
                $refund->reason,
                $attemptKey,
            );

            /*
             * The processor records a provider refusal rather than throwing — a decline is
             * an answer, not an error — so the outcome has to be read from the record it
             * wrote. Assuming success because nothing was thrown is how a customer ends up
             * told their money is on its way when the provider said no.
             */
            $attempt = PaymentTransaction::query()
                ->where('payment_intent_id', $intent->getKey())
                ->where('idempotency_key', $attemptKey)
                ->latest('occurred_at')
                ->first();

            if ($attempt !== null && $attempt->status === 'failed') {
                return $this->fail($refund, $attempt->error_message ?? 'Sağlayıcı iadeyi reddetti.');
            }
        } catch (Throwable $e) {
            /*
             * A provider outage is the commonest cause and the operation is safe to
             * repeat, so this is a state rather than an exception that escapes: the
             * customer is owed the money either way.
             */
            Log::warning('Ücret iadesi sağlayıcıda başarısız oldu.', [
                'refund' => $refund->getKey(),
                'message' => $e->getMessage(),
            ]);

            return $this->fail($refund, mb_substr($e->getMessage(), 0, 300));
        }

        return $this->settle($refund, $actor);
    }

    /** What could still be sent back on this order. */
    public function refundableMinor(Order $order): int
    {
        $already = (int) Refund::query()
            ->where('order_id', $order->getKey())
            ->whereIn('status', [RefundStatus::Processing->value, RefundStatus::Succeeded->value])
            ->sum('amount_minor');

        return max(0, $order->grand_total_minor - $already);
    }

    // --- internals -----------------------------------------------------------

    /**
     * Marks the refund done and posts the reversal.
     *
     * Per share: the seller's payable comes down by their part and commission by its part.
     * Posting the whole amount against commission would make the platform pay for the
     * seller's return.
     */
    private function settle(Refund $refund, ?User $actor): Refund
    {
        $settled = DB::transaction(function () use ($refund, $actor): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => RefundStatus::Succeeded,
                'processed_at' => now(),
                'failure_reason' => null,
            ])->save();

            $lines = [];

            if ($locked->seller_share_minor > 0 && $locked->seller_order_id !== null) {
                $lines[] = JournalLine::debit(
                    LedgerAccount::SellerPayable,
                    $locked->seller_share_minor,
                    (string) $locked->sellerOrder?->seller_id,
                    $locked->reference,
                );
            }

            if ($locked->commission_share_minor > 0) {
                $lines[] = JournalLine::debit(
                    LedgerAccount::Commission,
                    $locked->commission_share_minor,
                    (string) $locked->sellerOrder?->seller_id,
                    $locked->reference,
                );
            }

            $allocated = array_sum(array_map(
                static fn (JournalLine $line): int => $line->debitMinor,
                $lines,
            ));

            // Anything unattributed — a whole-order goodwill refund with several sellers —
            // lands on the platform rather than being quietly dropped.
            if ($allocated < $locked->amount_minor) {
                $lines[] = JournalLine::debit(
                    LedgerAccount::Commission,
                    $locked->amount_minor - $allocated,
                    memo: $locked->reference,
                );
            }

            // The money leaves the cash we are holding for this order.
            $lines[] = JournalLine::credit(
                LedgerAccount::CashProvider,
                $locked->amount_minor,
                memo: $locked->reference,
            );

            $this->ledger->post(
                type: 'order.refunded',
                description: 'İade: '.($locked->reason ?? $locked->reference),
                lines: $lines,
                referenceType: 'refund',
                referenceId: (string) $locked->getKey(),
                idempotencyKey: 'refund:'.$locked->getKey(),
                currency: $locked->currency,
                actor: $actor,
            );

            return $locked;
        });

        if ($settled->sellerOrder !== null) {
            $this->accounting->rebuildBalance(
                (string) $settled->sellerOrder->seller_id,
                $settled->currency,
            );
        }

        $this->audit->record(
            action: 'fulfilment.refund.succeeded',
            subject: $settled,
            context: ['reference' => $settled->reference, 'amount_minor' => $settled->amount_minor],
            actor: $actor,
        );

        return $settled;
    }

    private function fail(Refund $refund, string $reason): Refund
    {
        $refund->forceFill([
            'status' => RefundStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        return $refund;
    }

    /** What the platform keeps on the part of a line being returned. */
    private function commissionOn(ReturnItem $item): int
    {
        return (int) round(
            $item->unit_price_minor * $item->approved_quantity * $item->commission_rate_bps / 10_000,
        );
    }

    private function reference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'RF-'.now()->format('Ym').'-'.Str::upper(Str::random(5));

            if (! Refund::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('İade referansı üretilemedi.');
    }
}

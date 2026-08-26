<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Enums\LedgerAccount;
use App\Domains\Finance\Enums\SettlementStatus;
use App\Domains\Orders\Enums\SellerOrderStatus;
use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which of a seller's orders may actually be paid out.
 *
 * The conditions come from 06_SECURITY_PAYMENT_FINANCE_RULES.md, and each one is there
 * because of a way money leaves and does not come back:
 *
 *  - **the payment was captured** — an authorization the bank can still withdraw is not
 *    money, and paying a seller out of one means paying them with our own
 *  - **the goods were delivered** — a parcel still in a van can still be refused
 *  - **the hold period has passed** — the return window; paying before it closes means
 *    chasing a seller for money they have already spent
 *  - **nothing is open against it** — a return, a refund or a dispute
 *  - **the seller is still trading** — a suspended seller is suspended for a reason, and
 *    that reason is usually financial
 *  - **it is not already in a settlement** — a unique index enforces this too, because a
 *    bank transfer is not something you can recall
 *
 * The hold runs from delivery, not from the order date. A seller who ships late does not
 * get paid early for it, and a customer's fourteen days start when the box arrives.
 */
final class SettlementEligibility
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * The seller orders that could be settled today.
     *
     * @return Collection<int, SellerOrder>
     */
    public function eligible(string $sellerId, string $currency = 'TRY'): Collection
    {
        $cutoff = now()->subDays($this->holdDays());

        return SellerOrder::query()
            ->where('seller_id', $sellerId)
            ->where('currency', $currency)
            ->where('status', SellerOrderStatus::Delivered->value)
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', $cutoff)
            // The unique index would refuse a second one anyway; excluding them here is
            // what makes the figure on a seller's dashboard match what they will be paid.
            ->whereNotIn('id', fn ($query) => $query->select('seller_order_id')->from('settlement_items'))
            ->whereHas('seller', fn ($query) => $query->where('status', 'active'))
            ->whereHas('order.payment', fn ($query) => $query->whereIn('status', ['captured', 'partially_refunded']))
            /*
             * Nothing unresolved against it.
             *
             * A return that nobody has looked at yet counts, and that is deliberate: it is
             * exactly the case where paying out would be most embarrassing. Paying for
             * goods on their way back means chasing money from somebody who has spent it.
             */
            ->whereDoesntHave('returns', fn ($query) => $query->whereIn('status', [
                'requested', 'approved', 'in_transit', 'received',
            ]))
            ->orderBy('delivered_at')
            ->get();
    }

    /** What those orders are worth to the seller. */
    public function eligibleTotal(string $sellerId, string $currency = 'TRY'): int
    {
        return (int) $this->eligible($sellerId, $currency)
            ->sum(fn (SellerOrder $sellerOrder): int => $sellerOrder->payableMinor());
    }

    /** What is already committed to an open settlement and cannot be committed again. */
    public function reservedTotal(string $sellerId, string $currency = 'TRY'): int
    {
        return (int) DB::table('settlements')
            ->where('seller_id', $sellerId)
            ->where('currency', $currency)
            ->whereIn('status', [SettlementStatus::Draft->value, SettlementStatus::Approved->value])
            ->sum('net_minor');
    }

    /** What has actually left. */
    public function paidOutTotal(string $sellerId, string $currency = 'TRY'): int
    {
        return (int) DB::table('settlements')
            ->where('seller_id', $sellerId)
            ->where('currency', $currency)
            ->where('status', SettlementStatus::Paid->value)
            ->sum('net_minor');
    }

    /**
     * Why a particular order is not being paid yet.
     *
     * Sellers ask. An answer of "teslimattan 14 gün sonra" is something they can plan
     * around; silence is a support ticket, and "eligibility rules" is worse than silence.
     */
    public function explain(SellerOrder $sellerOrder): string
    {
        $sellerOrder->loadMissing(['order.payment', 'seller']);

        if ($sellerOrder->status === SellerOrderStatus::Cancelled) {
            return 'Sipariş iptal edildiği için hakedişe girmez.';
        }

        if ($sellerOrder->status !== SellerOrderStatus::Delivered) {
            return 'Teslim edildikten sonra hakediş süresi başlar.';
        }

        $payment = $sellerOrder->order?->payment;

        if ($payment !== null && ! $payment->status->isSettled()) {
            return 'Müşteri ödemesi henüz kesinleşmedi.';
        }

        if ($sellerOrder->seller?->status?->value !== 'active') {
            return 'Satıcı hesabı askıda olduğu için ödeme yapılamaz.';
        }

        $releaseAt = $sellerOrder->delivered_at?->copy()->addDays($this->holdDays());

        if ($releaseAt !== null && $releaseAt->isFuture()) {
            return sprintf('%s tarihinde hakedişe girer.', $releaseAt->format('d.m.Y'));
        }

        if (DB::table('returns')
            ->where('seller_order_id', $sellerOrder->getKey())
            ->whereIn('status', ['requested', 'approved', 'in_transit', 'received'])
            ->exists()) {
            return 'Açık bir iade talebi var; sonuçlanınca hakedişe girer.';
        }

        if (DB::table('settlement_items')->where('seller_order_id', $sellerOrder->getKey())->exists()) {
            return 'Bu sipariş bir hakediş dönemine dahil edildi.';
        }

        return 'Hakedişe hazır.';
    }

    /**
     * How long after delivery the money is held.
     *
     * The return window plus a margin. Configured rather than constant because it is a
     * commercial decision that changes, and changing it should not need a deploy.
     */
    public function holdDays(): int
    {
        return (int) config('refconcept.settlement.hold_days', 14);
    }

    /** What the ledger says the seller is owed in total, settled or not. */
    public function owedTotal(string $sellerId, string $currency = 'TRY'): int
    {
        return $this->ledger->balanceOf(
            LedgerAccount::SellerPayable,
            $sellerId,
            $currency,
        );
    }
}

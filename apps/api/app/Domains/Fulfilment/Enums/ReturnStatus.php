<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Enums;

/**
 * Where a return request has got to.
 *
 *   requested ──> approved ──> in_transit ──> received ──> completed
 *       │             │                           │
 *       ├──> rejected │                           └──> rejected  (arrived damaged)
 *       └──> cancelled└──> cancelled
 *
 * **`received` and `completed` are separate**, and that separation is the point. A parcel
 * arriving is a physical fact; deciding the return is finished is a decision that releases
 * money. Between them a seller opens the box, and quite often what is inside is not what
 * was described — so `received → rejected` is a real edge, not a modelling accident.
 *
 * The customer can cancel while nothing has moved. Once the goods are in transit they
 * cannot: a parcel already with a courier will arrive whatever anybody now wants, and a
 * cancelled return with goods in the warehouse is a seller holding stock nobody has
 * accounted for.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Talep alındı',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
            self::InTransit => 'Yolda',
            self::Received => 'Teslim alındı',
            self::Completed => 'Tamamlandı',
            self::Cancelled => 'İptal edildi',
        };
    }

    public function customerMessage(): string
    {
        return match ($this) {
            self::Requested => 'İade talebiniz satıcıya iletildi.',
            self::Approved => 'İadeniz onaylandı. Ürünü kargoya verebilirsiniz.',
            self::Rejected => 'İade talebiniz kabul edilmedi.',
            self::InTransit => 'İade kargonuz yolda.',
            self::Received => 'Ürün satıcıya ulaştı ve inceleniyor.',
            self::Completed => 'İadeniz tamamlandı; ücret iadesi işleme alındı.',
            self::Cancelled => 'İade talebiniz iptal edildi.',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            // Cancellable until the goods move: after that the parcel arrives whatever
            // anybody now wants.
            self::Approved => [self::InTransit, self::Received, self::Cancelled],
            self::InTransit => [self::Received],
            // What is in the box is frequently not what was described, so a seller can
            // still refuse after opening it.
            self::Received => [self::Completed, self::Rejected],
            self::Rejected, self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || in_array($next, $this->allowedNext(), true);
    }

    /**
     * Whether this return should stop the seller order being paid out.
     *
     * Anything unresolved does. A payout made while a return is open is money chased back
     * from somebody who has already spent it — which is the settlement-hold rule from
     * 06_SECURITY_PAYMENT_FINANCE_RULES.md.
     */
    public function blocksSettlement(): bool
    {
        return in_array($this, [
            self::Requested,
            self::Approved,
            self::InTransit,
            self::Received,
        ], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }

    /** Whether reaching this state means money should go back. */
    public function triggersRefund(): bool
    {
        return $this === self::Completed;
    }
}

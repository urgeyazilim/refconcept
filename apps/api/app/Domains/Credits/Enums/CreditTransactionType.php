<?php

declare(strict_types=1);

namespace App\Domains\Credits\Enums;

/**
 * Every way credits move.
 *
 * The types are not interchangeable labels; the direction of each is enforced by a CHECK
 * constraint, because a "consume" that adds credits is free money and would balance
 * perfectly in every report.
 *
 * `Reserve` and `Release` are worth explaining: both have an amount of zero. Holding
 * credits moves nothing — it changes what is *available*, not what is owned — and
 * recording a hold as a movement would make every sum over the ledger wrong.
 */
enum CreditTransactionType: string
{
    /** Bought with money. */
    case Purchase = 'purchase';

    /** Given by the platform: goodwill, support, a manual top-up. */
    case Grant = 'grant';

    case Promotion = 'promotion';

    /** Held for work that has not finished. */
    case Reserve = 'reserve';

    /** The work failed or was cancelled; the hold is returned. */
    case Release = 'release';

    /** The work succeeded; the hold becomes a spend. */
    case Consume = 'consume';

    case Expire = 'expire';

    /** A correction, in either direction. The only type that demands a reason. */
    case Adjustment = 'adjustment';

    /** Credits returned because the purchase behind them was refunded. */
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Satın alma',
            self::Grant => 'Tanımlama',
            self::Promotion => 'Promosyon',
            self::Reserve => 'Bloke',
            self::Release => 'Bloke çözüldü',
            self::Consume => 'Kullanım',
            self::Expire => 'Süre doldu',
            self::Adjustment => 'Düzeltme',
            self::Refund => 'İade',
        };
    }

    /** Whether this type adds credits to the balance. */
    public function isCredit(): bool
    {
        return in_array($this, [self::Purchase, self::Grant, self::Promotion, self::Refund], true);
    }

    /** Whether this type removes them. */
    public function isDebit(): bool
    {
        return in_array($this, [self::Consume, self::Expire], true);
    }

    /** Whether it only moves the line between held and available. */
    public function isHold(): bool
    {
        return in_array($this, [self::Reserve, self::Release], true);
    }

    /**
     * Whether a statement should show this to a customer.
     *
     * Holds are deliberately hidden. A reserve immediately followed by a consume is one
     * event to the person who ran a render, and showing three lines for it makes a
     * statement unreadable — which is how a customer stops checking it at all.
     */
    public function isVisibleToCustomer(): bool
    {
        return ! $this->isHold();
    }
}

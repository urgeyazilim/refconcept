<?php

declare(strict_types=1);

namespace App\Domains\Finance\Enums;

/**
 * Where a payout run has got to.
 *
 *   draft ──> approved ──> paid
 *     │           │
 *     └───────────┴──> cancelled
 *
 * `approved` is a real state, not a formality. Approving moves the money out of a seller's
 * available balance and into a reserve, so it cannot be paid twice or counted in a second
 * settlement while somebody is at a bank making the transfer. `paid` is recorded by a
 * person who has seen it leave, because until then it has not.
 *
 * Nothing comes back from `paid`. A transfer that went to the wrong place is corrected by
 * a reversing journal entry and a new settlement, never by editing this one — the rule
 * from 06_SECURITY_PAYMENT_FINANCE_RULES.md about never rewriting historical finance.
 */
enum SettlementStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Approved => 'Onaylandı',
            self::Paid => 'Ödendi',
            self::Cancelled => 'İptal edildi',
        };
    }

    /** Whether the money is still counted against the seller's balance. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled], true);
    }
}

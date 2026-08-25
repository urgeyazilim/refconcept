<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Enums;

/**
 * Where a basket is in its life.
 *
 *   open ──> checking_out ──> ordered
 *     │            │
 *     └────────────┴──> abandoned
 *
 * `checking_out` exists because that is the window in which stock is actually held. A cart
 * sitting open holds nothing — a browser tab left for a week must not keep a sofa off the
 * market — and the moment somebody starts paying, minutes of hold are taken and the cart
 * stops accepting changes. Without a separate state, "is this basket holding stock" would
 * be a question you answer by looking somewhere else.
 */
enum CartStatus: string
{
    case Open = 'open';
    case CheckingOut = 'checking_out';
    case Ordered = 'ordered';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::CheckingOut => 'Ödeme adımında',
            self::Ordered => 'Siparişe dönüştü',
            self::Abandoned => 'Terk edildi',
        };
    }

    /** Whether items can still be added or removed. */
    public function isEditable(): bool
    {
        return $this === self::Open;
    }

    /** Whether this cart currently holds stock. */
    public function holdsStock(): bool
    {
        return $this === self::CheckingOut;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Ordered, self::Abandoned], true);
    }
}

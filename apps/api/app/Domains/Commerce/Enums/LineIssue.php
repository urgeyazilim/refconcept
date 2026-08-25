<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Enums;

/**
 * What is wrong with a line, in the customer's terms.
 *
 * Revalidation exists because a basket is a promise made at a moment and honoured later,
 * and four things can happen in between. Each one needs a different sentence and a
 * different button, which is why this is an enum rather than a boolean:
 *
 *  - the price went up — show both numbers and let them decide
 *  - the price went down — the good case, and worth saying out loud
 *  - it sold out — the line has to go, and a customer told nothing would find out at
 *    payment, which is the worst possible moment
 *  - fewer are left than they asked for — reduce, do not remove
 *
 * A fifth case, the offer being withdrawn entirely, is treated as sold out: from the
 * customer's side there is no difference worth explaining.
 */
enum LineIssue: string
{
    case PriceIncreased = 'price_increased';
    case PriceDecreased = 'price_decreased';
    case OutOfStock = 'out_of_stock';
    case QuantityReduced = 'quantity_reduced';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::PriceIncreased => 'Fiyat arttı',
            self::PriceDecreased => 'Fiyat düştü',
            self::OutOfStock => 'Stokta kalmadı',
            self::QuantityReduced => 'Adet azaltıldı',
            self::Unavailable => 'Satışta değil',
        };
    }

    /**
     * Whether the customer has to acknowledge this before paying.
     *
     * A price that fell needs no acknowledgement — nobody is harmed by paying less, and
     * stopping a purchase to say "good news" is how a checkout loses people.
     */
    public function blocksCheckout(): bool
    {
        return $this !== self::PriceDecreased;
    }

    /** Whether the line can stay in the basket at all. */
    public function isFatal(): bool
    {
        return in_array($this, [self::OutOfStock, self::Unavailable], true);
    }
}

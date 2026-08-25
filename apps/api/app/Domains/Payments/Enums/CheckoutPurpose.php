<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * What the money is for.
 *
 * A bank sees one card payment either way; we see two entirely different obligations. A
 * basket owes somebody a parcel from each of several sellers and owes those sellers a
 * payout; a credit top-up owes nothing but a number in a wallet. Fulfilment branches on
 * this rather than on "is cart_id null", because a nullable column is a fact about the
 * schema and this is a fact about the business.
 */
enum CheckoutPurpose: string
{
    case Cart = 'cart';
    case Credits = 'credits';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Sepet',
            self::Credits => 'Kredi paketi',
        };
    }

    /** Whether completing this checkout has to consume a stock hold. */
    public function reservesStock(): bool
    {
        return $this === self::Cart;
    }
}

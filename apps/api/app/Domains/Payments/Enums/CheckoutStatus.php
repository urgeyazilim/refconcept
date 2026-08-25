<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * Where a checkout session is in its life.
 *
 *   open ──> awaiting_payment ──> paid
 *     │            │
 *     │            ├──> failed ──> awaiting_payment   (a declined card is retried)
 *     └────────────┴──> cancelled
 *                  └──> expired
 *
 * `failed` is not terminal, and that is deliberate: the overwhelmingly common reason a
 * payment fails is a card the customer can simply try again with. Ending the session on
 * the first decline would throw away the addresses and the price snapshot and make them
 * start over, which is both hostile and, since prices may have moved in between, a way to
 * quote a different total for the same basket.
 *
 * `expired` is what the stock hold's clock produces. The session and the hold expire
 * together; a session that outlived its hold would be a customer paying for stock we have
 * already given away.
 */
enum CheckoutStatus: string
{
    case Open = 'open';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::AwaitingPayment => 'Ödeme bekleniyor',
            self::Paid => 'Ödendi',
            self::Failed => 'Ödeme başarısız',
            self::Cancelled => 'İptal edildi',
            self::Expired => 'Süresi doldu',
        };
    }

    /** Whether a payment attempt may still be started against this session. */
    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Open, self::AwaitingPayment, self::Failed], true);
    }

    /** Whether this session is still holding stock. */
    public function holdsStock(): bool
    {
        return in_array($this, [self::Open, self::AwaitingPayment, self::Failed], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled, self::Expired], true);
    }
}

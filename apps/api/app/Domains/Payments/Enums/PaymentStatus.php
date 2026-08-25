<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * Where one attempt to collect money has got to.
 *
 *   created ──> requires_action ──> processing ──> authorized ──> captured
 *      │              │                  │             │             │
 *      │              │                  │             │             ├──> partially_refunded ──> refunded
 *      │              │                  │             │             └──> refunded
 *      ├──────────────┴──────────────────┴─────────────┴──> failed
 *      ├──────────────┴──────────────────┴─────────────┴──> cancelled
 *      └──────────────┴──────────────────┴────────────────> expired
 *
 * The transitions are enumerated rather than assumed, and that is the whole reason this
 * enum exists. Payment providers deliver news out of order: a webhook saying "captured"
 * can arrive before the browser has come back from 3DS, a "failed" can follow a success
 * because a retry of an older event was queued behind it. Code that simply assigns the
 * status it was just told about will happily move a captured payment back to processing,
 * and then the money is real and the record says it is not.
 *
 * So the rule is: a transition that is not listed is not applied. A late "failed" against
 * a captured payment is dropped on the floor — deliberately, and with the reason written
 * here rather than discovered later.
 *
 * `authorized` and `captured` are separate because they are separate events at a bank,
 * and because the moment we may hand over goods is the second one. Providers that only
 * do immediate sales simply pass through both in one step.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Başlatıldı',
            self::RequiresAction => 'Doğrulama bekleniyor',
            self::Processing => 'İşleniyor',
            self::Authorized => 'Provizyon alındı',
            self::Captured => 'Tahsil edildi',
            self::PartiallyRefunded => 'Kısmen iade edildi',
            self::Refunded => 'İade edildi',
            self::Failed => 'Başarısız',
            self::Cancelled => 'İptal edildi',
            self::Expired => 'Süresi doldu',
        };
    }

    /**
     * Whether this payment is still going somewhere.
     *
     * Used to decide whether a new attempt may be started for the same session — the
     * answer has to be no while one is open, or a customer who clicks twice pays twice.
     */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Created,
            self::RequiresAction,
            self::Processing,
            self::Authorized,
        ], true);
    }

    /** Whether money has actually landed. */
    public function isSettled(): bool
    {
        return in_array($this, [
            self::Captured,
            self::PartiallyRefunded,
            self::Refunded,
        ], true);
    }

    /** Whether nothing further will happen without a human. */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Refunded,
            self::Failed,
            self::Cancelled,
            self::Expired,
        ], true);
    }

    /**
     * Whether this payment entitles the customer to what they bought.
     *
     * Only a capture does. An authorization is a promise the bank can still withdraw, and
     * fulfilling against one is how a marketplace ships goods it never got paid for.
     */
    public function entitlesFulfilment(): bool
    {
        return in_array($this, [self::Captured, self::PartiallyRefunded], true);
    }

    /**
     * The states this one may legally become.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Created => [
                self::RequiresAction, self::Processing, self::Authorized, self::Captured,
                self::Failed, self::Cancelled, self::Expired,
            ],
            self::RequiresAction => [
                self::Processing, self::Authorized, self::Captured,
                self::Failed, self::Cancelled, self::Expired,
            ],
            self::Processing => [
                self::Authorized, self::Captured, self::Failed, self::Cancelled, self::Expired,
            ],
            // An authorization can still be voided, and it does expire on its own — most
            // banks release one after a week whether anybody captures it or not.
            self::Authorized => [self::Captured, self::Failed, self::Cancelled, self::Expired],

            self::Captured => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::PartiallyRefunded, self::Refunded],

            // Terminal. A refunded payment stays refunded; a failure that later "succeeds"
            // is a new attempt, not a resurrection of this one.
            self::Refunded, self::Failed, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            // Being told the same news twice is normal — providers retry — and it is not
            // a transition. Treated as allowed so callers do not have to special-case it.
            return true;
        }

        return in_array($next, $this->allowedNext(), true);
    }
}

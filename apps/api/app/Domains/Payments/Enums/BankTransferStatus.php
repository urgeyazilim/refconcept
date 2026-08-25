<?php

declare(strict_types=1);

namespace App\Domains\Payments\Enums;

/**
 * Where an expected bank transfer has got to.
 *
 *   awaiting_transfer ──> under_review ──> confirmed
 *          │                   │      └──> short_paid ──> confirmed
 *          │                   │      └──> over_paid
 *          │                   └────────> rejected
 *          └──> expired
 *
 * **Short and over payments are states, not flags**, and that is the decision this enum
 * exists to record. People transfer the wrong figure constantly: a typo, an intermediary
 * bank's fee taken in transit, two orders paid in one go. A boolean "paid?" forces an
 * operator to decide privately whether 4.997,50₺ is close enough to 5.000₺, and whatever
 * they decide leaves no trace of the decision. A named state makes the shortfall visible,
 * reportable, and somebody's to resolve.
 *
 * `short_paid` is not terminal: the customer transfers the difference and the same
 * transfer is confirmed. `over_paid` is settled — the goods are released, and the surplus
 * becomes a refund somebody has to make, which is a different piece of work with its own
 * record.
 */
enum BankTransferStatus: string
{
    case AwaitingTransfer = 'awaiting_transfer';
    case UnderReview = 'under_review';
    case Confirmed = 'confirmed';
    case ShortPaid = 'short_paid';
    case OverPaid = 'over_paid';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingTransfer => 'Havale bekleniyor',
            self::UnderReview => 'İnceleniyor',
            self::Confirmed => 'Onaylandı',
            self::ShortPaid => 'Eksik ödeme',
            self::OverPaid => 'Fazla ödeme',
            self::Rejected => 'Reddedildi',
            self::Expired => 'Süresi doldu',
        };
    }

    /** What the customer is told, in a sentence rather than a label. */
    public function customerMessage(): string
    {
        return match ($this) {
            self::AwaitingTransfer => 'Havalenizi bekliyoruz. Açıklama alanına referans kodunu yazmayı unutmayın.',
            self::UnderReview => 'Havaleniz finans ekibimizce kontrol ediliyor.',
            self::Confirmed => 'Ödemeniz alındı.',
            self::ShortPaid => 'Gönderilen tutar sipariş tutarından az. Farkı aynı referansla gönderdiğinizde siparişiniz tamamlanır.',
            self::OverPaid => 'Gönderilen tutar sipariş tutarından fazla. Siparişiniz onaylandı; fark tarafınıza iade edilecek.',
            self::Rejected => 'Havaleniz eşleştirilemedi. Finans ekibimiz sizinle iletişime geçecek.',
            self::Expired => 'Havale süresi doldu ve ayırdığımız ürünler serbest bırakıldı.',
        };
    }

    /** Whether money has actually arrived in the platform's account. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Confirmed, self::OverPaid], true);
    }

    /** Whether this transfer is still expected to complete. */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingTransfer, self::UnderReview, self::ShortPaid], true);
    }

    /** Whether an operator may still act on it. */
    public function isDecidable(): bool
    {
        return in_array($this, [self::AwaitingTransfer, self::UnderReview, self::ShortPaid], true);
    }

    /** Whether this transfer is holding stock. */
    public function holdsStock(): bool
    {
        return $this->isOpen();
    }
}

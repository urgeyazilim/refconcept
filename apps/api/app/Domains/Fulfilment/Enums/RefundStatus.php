<?php

declare(strict_types=1);

namespace App\Domains\Fulfilment\Enums;

/**
 * Where money going back has got to.
 *
 *   pending ──> processing ──> succeeded
 *      │             │
 *      │             └──> failed ──> processing   (retried)
 *      └──> cancelled
 *
 * Separate from the return's own lifecycle on purpose. Goods and money travel on different
 * timetables: a provider can refuse a refund for a payment that is too old, a bank can take
 * days, and a goodwill refund has no return behind it at all. Folding this into the return
 * would make each of those impossible to represent, and therefore impossible to fix.
 *
 * **`failed` is not terminal.** A provider outage is the commonest cause and the operation
 * is safe to repeat — the customer is owed the money either way, and a state machine that
 * gave up would leave somebody manually chasing it.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Processing => 'İşleniyor',
            self::Succeeded => 'Tamamlandı',
            self::Failed => 'Başarısız',
            self::Cancelled => 'İptal edildi',
        };
    }

    public function customerMessage(): string
    {
        return match ($this) {
            self::Pending => 'İade tutarınız işleme alınmayı bekliyor.',
            self::Processing => 'İade tutarınız bankanıza gönderiliyor.',
            self::Succeeded => 'İade tutarınız bankanıza gönderildi. Hesabınıza geçmesi birkaç gün sürebilir.',
            self::Failed => 'İade işlemi tamamlanamadı; ekibimiz ilgileniyor.',
            self::Cancelled => 'İade işlemi iptal edildi.',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Cancelled],
            self::Processing => [self::Succeeded, self::Failed],
            // Retryable: a provider outage is the commonest cause and the customer is owed
            // the money either way.
            self::Failed => [self::Processing, self::Cancelled],
            self::Succeeded, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || in_array($next, $this->allowedNext(), true);
    }

    /** Whether this refund still counts against what the platform owes. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Failed], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Cancelled], true);
    }
}

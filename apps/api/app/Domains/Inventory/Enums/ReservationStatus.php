<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

/**
 * The life of a hold on stock.
 *
 *   held ──dispatch──> consumed
 *     │
 *     ├──cancel──> released
 *     └──timeout──> expired
 *
 * `released` and `expired` are the same outcome for the balance and different facts
 * about the world: one is a customer who changed their mind, the other a basket
 * nobody came back to. Collapsing them would make abandonment unmeasurable.
 */
enum ReservationStatus: string
{
    case Held = 'held';
    case Released = 'released';
    case Consumed = 'consumed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Rezerve',
            self::Released => 'Serbest bırakıldı',
            self::Consumed => 'Sevk edildi',
            self::Expired => 'Süresi doldu',
        };
    }

    /** Whether this hold is still keeping stock out of other customers' reach. */
    public function isActive(): bool
    {
        return $this === self::Held;
    }
}

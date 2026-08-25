<?php

declare(strict_types=1);

namespace App\Domains\Credits\Enums;

/**
 * What became of a hold.
 *
 *   held ──> consumed   the work succeeded and the credits were spent
 *        ├─> released   the work failed or was cancelled; nothing was spent
 *        └─> expired    nobody ever came back for it and the sweeper let it go
 *
 * `Released` and `Expired` do the same thing to the balance and mean different things to
 * a person reading the record: one is a system that finished its job correctly, the other
 * is a request that vanished. Collapsing them would hide a class of bug that only shows
 * up as a slow leak of abandoned holds.
 */
enum ReservationStatus: string
{
    case Held = 'held';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Bloke',
            self::Consumed => 'Kullanıldı',
            self::Released => 'Serbest bırakıldı',
            self::Expired => 'Süresi doldu',
        };
    }

    public function isSettled(): bool
    {
        return $this !== self::Held;
    }
}

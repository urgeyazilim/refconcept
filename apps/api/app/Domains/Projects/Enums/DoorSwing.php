<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * Which way a door goes: which jamb it hangs on, and whether it opens into the room.
 *
 * Not decoration. A door that opens into the room sweeps a quarter circle of floor that
 * nothing may stand on; hung on the other jamb it sweeps the other quarter, and a wardrobe
 * that was fine is now hit every time somebody comes in. One that opens out of the room
 * sweeps the corridor instead and the floor inside it is free.
 *
 * The jamb is named along the wall's own axis — `start` is the jamb at the lower offset —
 * rather than "left" or "right", which reverse on two walls out of four. The screen says
 * left or right; the row says which end.
 */
enum DoorSwing: string
{
    case StartIn = 'start_in';
    case EndIn = 'end_in';
    case StartOut = 'start_out';
    case EndOut = 'end_out';

    public function opensIn(): bool
    {
        return $this === self::StartIn || $this === self::EndIn;
    }

    public function hingeAtStart(): bool
    {
        return $this === self::StartIn || $this === self::StartOut;
    }

    public function label(): string
    {
        return $this->opensIn() ? 'içeri açılır' : 'dışarı açılır';
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * Which way a door or a window goes: which jamb it hangs on, and whether it opens inward.
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

    /**
     * Hinged along its top edge and tipped inward: a vasistas, a tilt sash, a fanlight.
     *
     * Windows only. It has no jamb and sweeps no floor — it takes the air above whatever is
     * under it, which is why one goes over a door or behind a kitchen counter where a casement
     * would be in the way. Recorded because it is the difference between a window somebody can
     * open with the sofa where it is and one they cannot.
     */
    case TopHung = 'top_hung';

    public function opensIn(): bool
    {
        return $this === self::StartIn || $this === self::EndIn || $this === self::TopHung;
    }

    /** Whether it swings on a jamb at all: a top-hung sash does not. */
    public function hasJamb(): bool
    {
        return $this !== self::TopHung;
    }

    /**
     * Which ways this kind of opening can go.
     *
     * @return list<self>
     */
    public static function for(ConstraintType $type): array
    {
        return match ($type) {
            // A door hangs on a jamb, and a doorway with a fanlight is two openings.
            ConstraintType::Door, ConstraintType::BalconyDoor => [self::StartIn, self::EndIn, self::StartOut, self::EndOut],
            ConstraintType::Window => [self::StartIn, self::EndIn, self::StartOut, self::EndOut, self::TopHung],
            default => [],
        };
    }

    public function hingeAtStart(): bool
    {
        return $this === self::StartIn || $this === self::StartOut;
    }

    public function label(): string
    {
        return match ($this) {
            self::TopHung => 'üstten açılır',
            self::StartIn, self::EndIn => 'içeri açılır',
            self::StartOut, self::EndOut => 'dışarı açılır',
        };
    }
}

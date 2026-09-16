<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * What kind of door or window it is.
 *
 * "A window" is a hole; a customer's window is a double casement or a French balcony, and
 * the room drawn with the right one is the room they recognise. The variant does not change
 * the collision rules — a way through is a way through — but it does change what is drawn,
 * how wide the thing usually is, and what the render is told.
 *
 * Each variant belongs to one constraint type; `for()` says which are on offer for which.
 */
enum OpeningVariant: string
{
    // Windows.
    case SingleWindow = 'single';
    case DoubleWindow = 'double';
    case TripleWindow = 'triple';

    /** Floor-to-ceiling glazing with a guard rail outside and no balcony. */
    case FrenchBalcony = 'french_balcony';

    // Doors and balcony doors.
    case SingleDoor = 'single_door';
    case DoubleDoor = 'double_door';

    /** Two glass panels sliding past each other. Balcony doors only. */
    case Sliding = 'sliding';

    public function label(): string
    {
        return match ($this) {
            self::SingleWindow => 'Tek kanat pencere',
            self::DoubleWindow => 'Çift kanat pencere',
            self::TripleWindow => 'Üçlü pencere',
            self::FrenchBalcony => 'Fransız balkon',
            self::SingleDoor => 'Tek kanat kapı',
            self::DoubleDoor => 'Çift kanat kapı',
            self::Sliding => 'Sürgülü kapı',
        };
    }

    /**
     * Which variants a type can be.
     *
     * @return list<self>
     */
    public static function for(ConstraintType $type): array
    {
        return match ($type) {
            ConstraintType::Window => [self::SingleWindow, self::DoubleWindow, self::TripleWindow, self::FrenchBalcony],
            ConstraintType::Door => [self::SingleDoor, self::DoubleDoor],
            ConstraintType::BalconyDoor => [self::SingleDoor, self::DoubleDoor, self::Sliding],
            default => [],
        };
    }

    public function fits(ConstraintType $type): bool
    {
        return in_array($this, self::for($type), true);
    }

    /**
     * The kind an opening most likely is, judged by its width, for one read from a
     * photograph before anybody was asked.
     *
     * Casements are rarely wider than a metre and a door leaf rarely wider than 1.2 m; a
     * window that reaches the floor is a French balcony. Null for a type that has no kinds,
     * or when there is no width to judge by.
     */
    public static function guess(ConstraintType $type, mixed $widthMm, mixed $sillMm = null): ?self
    {
        if (! is_int($widthMm) || $widthMm <= 0) {
            return null;
        }

        return match ($type) {
            ConstraintType::Window => match (true) {
                is_int($sillMm) && $sillMm === 0 => self::FrenchBalcony,
                $widthMm < 1_000 => self::SingleWindow,
                $widthMm < 1_800 => self::DoubleWindow,
                default => self::TripleWindow,
            },
            ConstraintType::Door => $widthMm >= 1_300 ? self::DoubleDoor : self::SingleDoor,
            ConstraintType::BalconyDoor => match (true) {
                $widthMm >= 2_000 => self::Sliding,
                $widthMm >= 1_300 => self::DoubleDoor,
                default => self::SingleDoor,
            },
            default => null,
        };
    }

    /**
     * The size such a thing usually is, in millimetres: width, height, sill.
     *
     * Starting sizes for one put in from the palette, to be dragged and resized afterwards;
     * nothing here is an assumption about a customer's room.
     *
     * @return array{width_mm: int, height_mm: int, sill_height_mm: int}
     */
    public function typicalSize(): array
    {
        return match ($this) {
            self::SingleWindow => ['width_mm' => 900, 'height_mm' => 1_400, 'sill_height_mm' => 900],
            self::DoubleWindow => ['width_mm' => 1_400, 'height_mm' => 1_400, 'sill_height_mm' => 900],
            self::TripleWindow => ['width_mm' => 2_100, 'height_mm' => 1_400, 'sill_height_mm' => 900],
            self::FrenchBalcony => ['width_mm' => 1_200, 'height_mm' => 2_200, 'sill_height_mm' => 0],
            self::SingleDoor => ['width_mm' => 900, 'height_mm' => 2_100, 'sill_height_mm' => 0],
            self::DoubleDoor => ['width_mm' => 1_600, 'height_mm' => 2_100, 'sill_height_mm' => 0],
            self::Sliding => ['width_mm' => 2_400, 'height_mm' => 2_200, 'sill_height_mm' => 0],
        };
    }
}

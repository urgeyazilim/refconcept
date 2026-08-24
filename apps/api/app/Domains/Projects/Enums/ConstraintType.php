<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * The things in a room that furniture has to work around.
 *
 * Not decoration — this is the list the design engine reasons over. "There is a
 * window" is not enough to decide whether a 220 cm sofa fits: where it is, how wide it
 * is and how far off the floor it starts all change the answer, and whether it may be
 * covered at all changes it again.
 */
enum ConstraintType: string
{
    case Window = 'window';
    case Door = 'door';
    case BalconyDoor = 'balcony_door';
    case Radiator = 'radiator';
    case Column = 'column';
    case Beam = 'beam';
    case Socket = 'socket';
    case Switch_ = 'switch';

    /** Built-in wardrobes, kitchen units — furniture that is not going anywhere. */
    case FixedFurniture = 'fixed_furniture';

    case Fireplace = 'fireplace';
    case Stairs = 'stairs';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Window => 'Pencere',
            self::Door => 'Kapı',
            self::BalconyDoor => 'Balkon kapısı',
            self::Radiator => 'Radyatör',
            self::Column => 'Kolon',
            self::Beam => 'Kiriş',
            self::Socket => 'Priz',
            self::Switch_ => 'Anahtar',
            self::FixedFurniture => 'Sabit mobilya',
            self::Fireplace => 'Şömine',
            self::Stairs => 'Merdiven',
            self::Other => 'Diğer',
        };
    }

    /**
     * Whether furniture may stand in front of this by default.
     *
     * A column blocks; a socket does not, but you still want it recorded — a sideboard
     * across the only socket on that wall is a design nobody can live with.
     */
    public function blocksByDefault(): bool
    {
        return match ($this) {
            self::Socket, self::Switch_ => false,
            default => true,
        };
    }

    /**
     * Whether the design must leave it visible even if nothing is placed against it.
     *
     * A window covered by a tall bookcase is technically a valid layout and a dark
     * room, so daylight sources default to staying clear.
     */
    public function mustStayVisibleByDefault(): bool
    {
        return match ($this) {
            self::Window, self::BalconyDoor, self::Door, self::Fireplace, self::Stairs => true,
            default => false,
        };
    }

    /**
     * @return array<int, array{value: string, label: string, blocks: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'blocks' => $case->blocksByDefault(),
            ],
            self::cases(),
        );
    }
}

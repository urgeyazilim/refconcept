<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

/**
 * The rooms RefConcept knows how to furnish.
 *
 * One vocabulary, shared deliberately. A customer's room and a product category carry
 * the same value, and that is the whole basis of matching: a bedroom design offers
 * bedroom furniture because the two agree on what "bedroom" is. Two lists that drift —
 * "kids_room" here, "child_bedroom" there — produce a design engine that silently
 * finds nothing and no error anywhere to explain it.
 *
 * Not every value has catalogue coverage yet; the seeded taxonomy tags five of these.
 * A customer may still create a room of any type, and the matching engine will
 * correctly find little for it, which is better than refusing to let somebody describe
 * their own home.
 */
enum RoomType: string
{
    case LivingRoom = 'living_room';
    case Bedroom = 'bedroom';
    case KidsRoom = 'kids_room';
    case DiningRoom = 'dining_room';
    case Kitchen = 'kitchen';
    case Bathroom = 'bathroom';
    case Office = 'office';
    case Hallway = 'hallway';
    case Balcony = 'balcony';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LivingRoom => 'Oturma odası',
            self::Bedroom => 'Yatak odası',
            self::KidsRoom => 'Çocuk odası',
            self::DiningRoom => 'Yemek odası',
            self::Kitchen => 'Mutfak',
            self::Bathroom => 'Banyo',
            self::Office => 'Çalışma odası',
            self::Hallway => 'Antre / koridor',
            self::Balcony => 'Balkon',
            self::Other => 'Diğer',
        };
    }

    /**
     * Every value, for a select or a filter row.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}

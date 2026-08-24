<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

enum LocationType: string
{
    case Warehouse = 'warehouse';
    case Store = 'store';

    /** Stock the seller never holds: the supplier ships it. */
    case Dropship = 'dropship';

    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => 'Depo',
            self::Store => 'Mağaza',
            self::Dropship => 'Tedarikçiden sevk',
            self::Supplier => 'Tedarikçi stoğu',
        };
    }
}

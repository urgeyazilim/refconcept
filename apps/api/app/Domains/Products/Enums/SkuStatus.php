<?php

declare(strict_types=1);

namespace App\Domains\Products\Enums;

/** The state of one seller's offer. Mirrored by a CHECK constraint. */
enum SkuStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case OutOfStock = 'out_of_stock';
    case Archived = 'archived';

    /** Whether this offer may be shown and added to a cart. */
    public function isPurchasable(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Active => 'Satışta',
            self::Paused => 'Duraklatıldı',
            self::OutOfStock => 'Stokta yok',
            self::Archived => 'Arşivlendi',
        };
    }
}

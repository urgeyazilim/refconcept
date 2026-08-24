<?php

declare(strict_types=1);

namespace App\Domains\Products\Enums;

/**
 * Whether the seller intends this product to be sold at all.
 *
 * Separate from moderation on purpose: an approved product can still be paused by its
 * seller, and pausing must not require another review to undo.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Active => 'Yayında',
            self::Archived => 'Arşivlendi',
        };
    }
}

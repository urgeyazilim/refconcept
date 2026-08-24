<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/** Lifecycle of an approved seller. Mirrored by a CHECK constraint. */
enum SellerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Whether the seller may trade: list products, receive orders and be paid out.
     * A suspended seller keeps their data and their obligations, but sells nothing.
     */
    public function canTrade(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Suspended => 'Askıya alındı',
            self::Closed => 'Kapatıldı',
        };
    }
}

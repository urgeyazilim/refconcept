<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/** Review state of an uploaded document. Mirrored by a CHECK constraint. */
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'İnceleniyor',
            self::Approved => 'Onaylandı',
            self::Rejected => 'Reddedildi',
        };
    }
}

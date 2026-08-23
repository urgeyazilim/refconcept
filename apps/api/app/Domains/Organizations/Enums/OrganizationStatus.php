<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/** Mirrored by a CHECK constraint on organizations.status. */
enum OrganizationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /** Whether members may act on behalf of this organization. */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/** Mirrored by a CHECK constraint on organization_users.status. */
enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';

    /**
     * Only an active membership conveys access. Everything else — including a
     * pending invitation — reads as "not a member" for authorization purposes.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}

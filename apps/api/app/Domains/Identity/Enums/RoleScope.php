<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Where a role's permissions apply. Mirrored by a CHECK constraint on roles.scope.
 */
enum RoleScope: string
{
    /** Applies across the whole platform (staff, support, super admin). */
    case Platform = 'platform';

    /** Applies only inside the organization the grant names (seller staff). */
    case Organization = 'organization';
}

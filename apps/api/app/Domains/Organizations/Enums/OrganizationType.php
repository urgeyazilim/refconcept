<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Enums;

/** Mirrored by a CHECK constraint on organizations.type. */
enum OrganizationType: string
{
    /** A marketplace seller (Phase 2 onboarding attaches a `sellers` row). */
    case Seller = 'seller';

    /** An interior designer / contractor practice (V2+). */
    case Professional = 'professional';

    /** RefConcept's own operational grouping. */
    case Internal = 'internal';
}

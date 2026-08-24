<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/** Mirrored by a CHECK constraint on seller_tax_profiles.taxpayer_type. */
enum TaxpayerType: string
{
    case Corporate = 'corporate';
    case SoleProprietor = 'sole_proprietor';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Corporate => 'Şirket',
            self::SoleProprietor => 'Şahıs şirketi',
            self::Individual => 'Bireysel',
        };
    }

    /** Corporate sellers are identified by VKN; individuals by TCKN. */
    public function requiresTaxNumber(): bool
    {
        return $this !== self::Individual;
    }
}

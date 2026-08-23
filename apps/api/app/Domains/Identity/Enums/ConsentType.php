<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * KVKK / contractual consents. Mirrored by a CHECK constraint on consents.type.
 */
enum ConsentType: string
{
    case PrivacyNotice = 'privacy_notice';
    case Terms = 'terms';
    case Marketing = 'marketing';
    case Cookies = 'cookies';
    case DataTransfer = 'data_transfer';

    /**
     * Consents a registration cannot proceed without. Marketing is deliberately not
     * here: bundling it with mandatory terms is exactly what KVKK forbids.
     *
     * @return array<int, self>
     */
    public static function requiredForRegistration(): array
    {
        return [self::PrivacyNotice, self::Terms];
    }
}

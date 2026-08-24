<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/** Documents an onboarding seller may be asked for. Mirrored by a CHECK constraint. */
enum DocumentType: string
{
    case TaxCertificate = 'tax_certificate';
    case TradeRegistryGazette = 'trade_registry_gazette';
    case SignatureCircular = 'signature_circular';
    case IdentityDocument = 'identity_document';
    case BankAccountProof = 'bank_account_proof';
    case ActivityCertificate = 'activity_certificate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TaxCertificate => 'Vergi levhası',
            self::TradeRegistryGazette => 'Ticaret sicil gazetesi',
            self::SignatureCircular => 'İmza sirküleri',
            self::IdentityDocument => 'Kimlik belgesi',
            self::BankAccountProof => 'Banka hesap belgesi',
            self::ActivityCertificate => 'Faaliyet belgesi',
            self::Other => 'Diğer',
        };
    }

    /**
     * Required before an application can be submitted, by taxpayer type.
     *
     * A sole proprietor has no trade registry gazette or signature circular, so
     * demanding them would make onboarding impossible for a legitimate applicant.
     *
     * @return array<int, self>
     */
    public static function requiredFor(TaxpayerType $taxpayerType): array
    {
        return match ($taxpayerType) {
            TaxpayerType::Corporate => [
                self::TaxCertificate,
                self::TradeRegistryGazette,
                self::SignatureCircular,
            ],
            TaxpayerType::SoleProprietor => [
                self::TaxCertificate,
                self::IdentityDocument,
            ],
            TaxpayerType::Individual => [
                self::IdentityDocument,
            ],
        };
    }
}

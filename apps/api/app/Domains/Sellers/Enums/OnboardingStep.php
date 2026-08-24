<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Enums;

/**
 * The checklist a seller works through. Completion is derived from the data itself
 * rather than stored as a flag someone can set — a "completed" step with no bank
 * account behind it would be a lie the UI happily repeats.
 */
enum OnboardingStep: string
{
    case Company = 'company';
    case LegalEntity = 'legal_entity';
    case Contacts = 'contacts';
    case Address = 'address';
    case BankAccount = 'bank_account';
    case TaxProfile = 'tax_profile';
    case Documents = 'documents';
    case Agreements = 'agreements';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Firma bilgileri',
            self::LegalEntity => 'Yasal bilgiler',
            self::Contacts => 'İletişim kişileri',
            self::Address => 'Adres',
            self::BankAccount => 'Banka hesabı',
            self::TaxProfile => 'Vergi profili',
            self::Documents => 'Belgeler',
            self::Agreements => 'Sözleşmeler',
        };
    }
}

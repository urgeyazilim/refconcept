<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * What kind of place this is.
 *
 * Kept because it changes what a good design looks like, not for reporting. A rental
 * cannot take fitted furniture or anything that needs drilling; a hospitality project
 * needs pieces that survive strangers. The design engine reads this.
 */
enum ProjectType: string
{
    case Home = 'home';
    case Rental = 'rental';
    case Office = 'office';
    case Hospitality = 'hospitality';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Ev',
            self::Rental => 'Kiralık ev',
            self::Office => 'Ofis',
            self::Hospitality => 'Otel / konaklama',
            self::Other => 'Diğer',
        };
    }

    /** A hint the design engine uses: fitted furniture is a landlord's decision. */
    public function allowsFixedInstallation(): bool
    {
        return $this !== self::Rental;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}

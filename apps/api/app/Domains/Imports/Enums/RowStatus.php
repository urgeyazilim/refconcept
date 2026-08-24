<?php

declare(strict_types=1);

namespace App\Domains\Imports\Enums;

enum RowStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Imported = 'imported';

    /** Valid, but the seller chose not to apply it — an unchanged row, for instance. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Valid => 'Geçerli',
            self::Invalid => 'Hatalı',
            self::Imported => 'Aktarıldı',
            self::Skipped => 'Atlandı',
        };
    }
}

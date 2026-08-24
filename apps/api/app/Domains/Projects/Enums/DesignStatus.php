<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * The state of a design as a whole, derived from its versions.
 *
 * A design is `generating` while any version is in flight and `ready` once at least
 * one has finished — because that is what the customer sees: a card that is either
 * still working or has something to show.
 */
enum DesignStatus: string
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Generating => 'Oluşturuluyor',
            self::Ready => 'Hazır',
            self::Failed => 'Başarısız',
            self::Archived => 'Arşivlendi',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }
}

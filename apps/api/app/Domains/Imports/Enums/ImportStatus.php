<?php

declare(strict_types=1);

namespace App\Domains\Imports\Enums;

/**
 * How far a spreadsheet has got.
 *
 *   uploaded ──parse──> analysing ──> mapped ──validate──> validating ──> validated
 *                                                                             │
 *                                                                         commit
 *                                                                             ▼
 *                                                        importing ──> completed
 *
 * `validated` is the dry run: every row has been checked and nothing has been written.
 * A seller sees exactly what will happen — how many products created, how many
 * updated, which lines are wrong — and only then commits. An import that writes as it
 * validates leaves the catalogue half-changed when line 250 turns out to be malformed,
 * and there is no undo for a catalogue.
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Analysing = 'analysing';
    case Mapped = 'mapped';
    case Validating = 'validating';
    case Validated = 'validated';
    case Importing = 'importing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Yüklendi',
            self::Analysing => 'İnceleniyor',
            self::Mapped => 'Eşleştirildi',
            self::Validating => 'Kontrol ediliyor',
            self::Validated => 'Ön izleme hazır',
            self::Importing => 'Aktarılıyor',
            self::Completed => 'Tamamlandı',
            self::Failed => 'Başarısız',
        };
    }

    /** Whether the seller can still change the column mapping. */
    public function isMappable(): bool
    {
        return in_array($this, [self::Mapped, self::Validated], true);
    }

    /** Whether a dry run's result is on the table, waiting to be committed. */
    public function isCommittable(): bool
    {
        return $this === self::Validated;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    /** Whether work is happening and the seller should be told to wait. */
    public function isRunning(): bool
    {
        return in_array($this, [self::Analysing, self::Validating, self::Importing], true);
    }
}

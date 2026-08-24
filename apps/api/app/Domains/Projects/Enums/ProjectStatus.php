<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * Where a customer is with a project.
 *
 *   draft ──> active ──> completed ──> archived
 *               ↑            │
 *               └────────────┘
 *
 * `completed` is not final. People finish a living room, live in it for a year and
 * then decide the rug was wrong; reopening has to be a click rather than a new
 * project that loses every design they were comparing against.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Active => 'Devam ediyor',
            self::Completed => 'Tamamlandı',
            self::Archived => 'Arşivlendi',
        };
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            self::Active => [self::Completed, self::Archived],
            self::Completed => [self::Active, self::Archived],
            // Archiving is reversible: it is tidying up, not deleting.
            self::Archived => [self::Active],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Whether rooms and designs can still be added or changed. */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
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

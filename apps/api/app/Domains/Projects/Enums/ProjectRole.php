<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * What somebody the owner invited may do.
 *
 * Two roles, deliberately. The realistic cases are a partner who should be able to
 * change things and an interior designer or parent who should be able to look — and
 * anything finer than that is a permissions matrix nobody will configure correctly.
 *
 * Neither role can delete the project, transfer it, or invite anybody else. Those stay
 * with the owner, because a shared account that can give itself away is not shared,
 * it is lost.
 */
enum ProjectRole: string
{
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Editor => 'Düzenleyebilir',
            self::Viewer => 'Görüntüleyebilir',
        };
    }

    public function canEdit(): bool
    {
        return $this === self::Editor;
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

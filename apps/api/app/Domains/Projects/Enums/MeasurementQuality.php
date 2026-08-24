<?php

declare(strict_types=1);

namespace App\Domains\Projects\Enums;

/**
 * Where a room's measurements came from.
 *
 * Kept because the difference changes what a design *means*. A sofa placed against an
 * estimated wall is a suggestion; the same sofa against a scanned wall is close to a
 * promise, and the customer is entitled to know which one they are looking at before
 * they spend forty thousand lira on it.
 *
 *   unknown    nobody has said anything
 *   estimated  guessed from a photograph
 *   manual     somebody measured with a tape
 *   scanned    a phone produced them (RoomPlan / ARCore, Phase 17)
 *   verified   a professional confirmed them on site
 */
enum MeasurementQuality: string
{
    case Unknown = 'unknown';
    case Estimated = 'estimated';
    case Manual = 'manual';
    case Scanned = 'scanned';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Ölçü girilmedi',
            self::Estimated => 'Tahmini',
            self::Manual => 'Elle ölçüldü',
            self::Scanned => 'Telefonla tarandı',
            self::Verified => 'Yerinde doğrulandı',
        };
    }

    /**
     * Whether measurements are required for this quality to be claimed.
     *
     * Mirrors the CHECK constraint on `rooms`: claiming a room was measured while
     * leaving the numbers empty would put a confident badge on a room with nothing
     * behind it, and the design engine would believe it.
     */
    public function requiresDimensions(): bool
    {
        return match ($this) {
            self::Unknown, self::Estimated => false,
            self::Manual, self::Scanned, self::Verified => true,
        };
    }

    /** How much the matching engine should trust these numbers, in basis points. */
    public function confidenceBps(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Estimated => 5000,
            self::Manual => 8500,
            self::Scanned => 9500,
            self::Verified => 10000,
        };
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

<?php

declare(strict_types=1);

namespace App\Domains\Matching\Enums;

/**
 * What became of a suggestion.
 *
 * `Replaced` is separate from `Rejected` on purpose: a customer who swapped a sofa for a
 * different one told us something useful about the first, and a customer who dismissed
 * the whole idea of a sofa told us something else entirely. Collapsing them would lose
 * the only signal in this system that comes from a person rather than from the system
 * marking its own homework.
 */
enum MatchStatus: string
{
    case Suggested = 'suggested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Suggested => 'Önerildi',
            self::Accepted => 'Seçildi',
            self::Rejected => 'Beğenilmedi',
            self::Replaced => 'Değiştirildi',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Suggested;
    }
}

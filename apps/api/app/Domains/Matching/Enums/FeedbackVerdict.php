<?php

declare(strict_types=1);

namespace App\Domains\Matching\Enums;

/**
 * Why a customer did not want a suggestion.
 *
 * A plain thumbs-down tells us a match was wrong and nothing about how. These verdicts
 * are the four ways matching actually fails, and each points at a different part of the
 * pipeline: the wrong category is a planning problem, the wrong size is a filter that did
 * not fire, too expensive is a budget that was not applied, and the wrong style is the
 * embedding — the only one of the four that needs a model to fix.
 *
 * Deliberately short. A list of twelve reasons is a list nobody reads to the end, and the
 * ones past the fourth would be pressed by accident.
 */
enum FeedbackVerdict: string
{
    case Good = 'good';
    case Bad = 'bad';
    case WrongCategory = 'wrong_category';
    case TooExpensive = 'too_expensive';
    case WrongStyle = 'wrong_style';
    case WrongSize = 'wrong_size';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Beğendim',
            self::Bad => 'Uygun değil',
            self::WrongCategory => 'Yanlış kategori',
            self::TooExpensive => 'Çok pahalı',
            self::WrongStyle => 'Tarzıma uymuyor',
            self::WrongSize => 'Ölçü uymuyor',
        };
    }

    public function isPositive(): bool
    {
        return $this === self::Good;
    }

    /**
     * Which part of the pipeline this points at.
     *
     * Not used to change anything automatically — that would be a system tuning itself on
     * a handful of clicks. It is what makes a week of feedback readable: forty "wrong
     * size" verdicts is a filter bug, and forty "wrong style" is a modelling problem.
     */
    public function blames(): string
    {
        return match ($this) {
            self::WrongCategory => 'planning',
            self::WrongSize => 'filters',
            self::TooExpensive => 'budget',
            self::WrongStyle => 'similarity',
            default => 'overall',
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

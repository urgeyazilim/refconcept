<?php

declare(strict_types=1);

namespace App\Domains\Credits\Exceptions;

use RuntimeException;

/**
 * The wallet cannot cover what was asked of it.
 *
 * Carries both numbers because the message a customer sees is only useful with them:
 * "8 kredi gerekiyor, 3 krediniz var" tells them what to do next, and "yetersiz bakiye"
 * does not.
 *
 * The three factories differ only in wording, and deliberately so. A render refused for
 * lack of credits is an ordinary event a customer resolves by buying more; an admin
 * adjustment refused for the same reason is a member of staff being told the correction
 * they typed would drive somebody's balance negative. Same arithmetic, different sentence,
 * different person reading it.
 */
final class InsufficientCredits extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $required,
        public readonly int $available,
    ) {
        parent::__construct($message);
    }

    public static function forReservation(int $required, int $available): self
    {
        return new self(
            sprintf('Bu işlem %d kredi gerektiriyor; kullanılabilir bakiyeniz %d kredi.', $required, $available),
            $required,
            $available,
        );
    }

    public static function forSpend(int $required, int $available): self
    {
        return new self(
            sprintf('Bu işlem %d kredi gerektiriyor; kullanılabilir bakiyeniz %d kredi.', $required, $available),
            $required,
            $available,
        );
    }

    public static function forAdjustment(int $required, int $available): self
    {
        return new self(
            sprintf(
                'Bu düzeltme %d kredi düşürüyor; cüzdanda yalnızca %d kredi var ve bakiye eksiye düşemez.',
                $required,
                $available,
            ),
            $required,
            $available,
        );
    }

    /** How many more credits would be needed. */
    public function shortfall(): int
    {
        return max(0, $this->required - $this->available);
    }
}

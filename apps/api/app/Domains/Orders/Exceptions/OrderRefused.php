<?php

declare(strict_types=1);

namespace App\Domains\Orders\Exceptions;

use RuntimeException;

/**
 * The order would not move.
 *
 * The message is written for whoever pressed the button — usually a seller — and the
 * status travels with it so a controller cannot invent a different answer.
 */
final class OrderRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function badTransition(string $from, string $to): self
    {
        // Names both states rather than saying "invalid": a seller looking at a stale
        // screen needs to know what it actually is now, not that they were wrong.
        return new self(
            sprintf('Bu sipariş "%s" durumundan "%s" durumuna geçemez.', $from, $to),
            409,
        );
    }

    public static function reasonRequired(): self
    {
        return new self('İptal için bir gerekçe yazmanız gerekiyor.', 422);
    }

    public static function notYours(): self
    {
        // 404 rather than 403: whether another seller's order exists is not something to
        // confirm to a competitor.
        return new self('Sipariş bulunamadı.', 404);
    }
}

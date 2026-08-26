<?php

declare(strict_types=1);

namespace App\Domains\Finance\Exceptions;

use RuntimeException;

/**
 * The payout would not go through.
 *
 * Written for the operator holding the screen, with the status that travels with it so a
 * controller cannot invent a different answer.
 */
final class SettlementRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function nothingEligible(): self
    {
        return new self('Bu satıcı için hakedişe hazır sipariş yok.', 422);
    }

    public static function nothingToPay(): self
    {
        return new self('Ödenecek tutar sıfır.', 422);
    }

    public static function alreadyDecided(string $status): self
    {
        // Names what it is now, so a second operator on a stale screen learns something
        // rather than being told they were wrong.
        return new self(sprintf('Bu hakediş zaten sonuçlandırılmış (%s).', $status), 409);
    }

    public static function notApproved(string $status): self
    {
        return new self(
            sprintf('Ödendi işaretlemek için hakedişin onaylanmış olması gerekir (şu an: %s).', $status),
            409,
        );
    }

    public static function notYours(): self
    {
        return new self('Hakediş bulunamadı.', 404);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Products\Exceptions;

use App\Domains\Products\Enums\ModerationStatus;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A listing that cannot move where it was asked to move.
 *
 * Carries the missing requirements so the response can tell the seller what to fix,
 * rather than a bare "cannot submit" they have to guess at.
 */
final class ProductNotSubmittable extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing
     */
    private function __construct(string $message, public readonly array $missing = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(
            'Ürün eksik olduğu için gönderilemez: '.implode(', ', $missing),
            $missing,
        );
    }

    public static function badTransition(ModerationStatus $from, ModerationStatus $to): self
    {
        return new self(
            "Ürün '{$from->label()}' durumundan '{$to->label()}' durumuna geçirilemez."
        );
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'moderation_status' => [$this->getMessage()],
        ]);
    }
}

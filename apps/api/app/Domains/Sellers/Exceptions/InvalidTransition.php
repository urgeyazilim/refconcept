<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Exceptions;

use App\Domains\Sellers\Enums\ApplicationStatus;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A refused state change.
 *
 * Carries enough detail for the response to tell the user what to do next, rather
 * than a bare 422 that leaves them guessing which field is at fault.
 */
final class InvalidTransition extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingSteps
     */
    private function __construct(
        string $message,
        public readonly array $missingSteps = [],
    ) {
        parent::__construct($message);
    }

    public static function between(ApplicationStatus $from, ApplicationStatus $to): self
    {
        return new self(
            "Başvuru '{$from->label()}' durumundan '{$to->label()}' durumuna geçirilemez."
        );
    }

    /**
     * @param  array<int, string>  $missingSteps
     */
    public static function incomplete(array $missingSteps): self
    {
        return new self(
            'Başvuru tamamlanmadan gönderilemez. Eksik adımlar: '.implode(', ', $missingSteps),
            $missingSteps,
        );
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'status' => [$this->getMessage()],
        ]);
    }
}

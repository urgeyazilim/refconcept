<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Not enough sellable stock to satisfy a request.
 *
 * Carries the numbers rather than only a message, because every caller needs to say
 * something different with them: a checkout tells the customer how many are left, an
 * import writes the shortfall against a row, and an order-fulfilment job logs it.
 */
final class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly string $skuCode,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            '%s için yeterli stok yok: %d adet istendi, %d adet satılabilir.',
            $skuCode,
            $requested,
            $available,
        ));
    }

    public function toValidationException(string $field = 'quantity'): ValidationException
    {
        return ValidationException::withMessages([$field => [$this->getMessage()]]);
    }
}

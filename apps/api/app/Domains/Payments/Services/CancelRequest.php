<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\PaymentIntent;

/**
 * A request to void a payment that has not settled.
 *
 * Distinct from a refund, and the difference is not cosmetic: a void removes an
 * authorization before any money moves, costs nothing and leaves no trace on the
 * customer's statement, while a refund moves money twice and shows up as two lines. Using
 * a refund where a void would do is a small cost we pay and a confusing statement the
 * customer reads.
 */
final readonly class CancelRequest
{
    public function __construct(
        public PaymentIntent $intent,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {}
}

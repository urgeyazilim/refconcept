<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\PaymentIntent;

/**
 * A request to send money back.
 *
 * The amount is explicit even for a full refund. "Refund everything" sounds simpler until
 * a partial refund has already happened and the two sides disagree about what everything
 * now means — so the caller states the figure and the processor checks it against what is
 * left, rather than both of them computing it separately.
 */
final readonly class RefundRequest
{
    public function __construct(
        public PaymentIntent $intent,
        public int $amountMinor,
        public string $currency = 'TRY',
        public ?string $reason = null,
        /**
         * Required in practice: a refund is the operation most likely to be retried after
         * a timeout, and the one where a duplicate costs real money.
         */
        public ?string $idempotencyKey = null,
    ) {}
}

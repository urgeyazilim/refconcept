<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

/**
 * What the provider did with a refund request.
 *
 * Refunds are frequently asynchronous — the provider accepts the instruction and the money
 * moves days later — so `pending` is a real outcome and not a failure. Recording it as a
 * success would tell a customer their money is back when it is not; recording it as a
 * failure would have somebody refund them a second time.
 */
final readonly class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $succeeded,
        public int $amountMinor = 0,
        public ?string $externalId = null,
        public bool $pending = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}
}

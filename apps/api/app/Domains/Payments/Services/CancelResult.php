<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

/** What the provider did with a cancellation request. */
final readonly class CancelResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $succeeded,
        public ?string $externalId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}
}

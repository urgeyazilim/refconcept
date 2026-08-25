<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

/** A yes-or-no answer from a provider, with its reason kept. */
final readonly class GatewayResult
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

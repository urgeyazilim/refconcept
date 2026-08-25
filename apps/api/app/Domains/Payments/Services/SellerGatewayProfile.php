<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

/**
 * A seller as the payment provider knows them.
 *
 * The sub-merchant key is the only part that matters to us: it is what a payment's lines
 * are attributed to. Everything else the provider stores about a seller is the provider's
 * copy of what we already hold, and duplicating it here would give us two records that
 * drift.
 */
final readonly class SellerGatewayProfile
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gateway,
        public string $externalSubMerchantKey,
        public ?string $status = null,
        public array $raw = [],
    ) {}
}

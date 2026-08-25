<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\PaymentIntent;

/**
 * Everything an adapter needs to start one payment, and nothing more.
 *
 * Note what is not here: no card number, no CVV, no expiry. Card data never enters this
 * process — the customer types it into the provider's own hosted form or its SDK, and we
 * receive a token or a redirect. That is not caution, it is the difference between being
 * in PCI-DSS scope and not, and no debugging convenience is worth crossing it.
 *
 * `basket` is included because marketplace providers require the lines to attribute money
 * to sub-merchants, and because a payment page showing "3 items" when the customer bought
 * four is a support call. It carries names and amounts, never anything personal beyond
 * what the provider already needs.
 */
final readonly class PaymentRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $basket
     * @param  array<string, mixed>  $buyer
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<string, mixed>  $billingAddress
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public PaymentIntent $intent,
        public int $amountMinor,
        public string $currency,
        /**
         * The token the customer's card was turned into by the provider's own form, when
         * the flow uses one. Absent for redirect flows, where the customer will type the
         * card on the provider's page.
         */
        public ?string $paymentToken = null,
        public array $basket = [],
        public array $buyer = [],
        public array $shippingAddress = [],
        public array $billingAddress = [],
        /** Where the provider sends the browser back after 3DS. */
        public ?string $returnUrl = null,
        /**
         * The caller's idempotency key, passed through to providers that honour one.
         * Retrying a create with the same key must not create a second payment.
         */
        public ?string $idempotencyKey = null,
        public array $metadata = [],
        /** The customer's address, for fraud scoring. Not used for anything else. */
        public ?string $clientIp = null,
    ) {}
}

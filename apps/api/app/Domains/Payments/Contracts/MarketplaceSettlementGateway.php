<?php

declare(strict_types=1);

namespace App\Domains\Payments\Contracts;

use App\Domains\Payments\Services\GatewayResult;
use App\Domains\Payments\Services\SellerGatewayProfile;
use App\Domains\Sellers\Models\Seller;

/**
 * The extra things a *marketplace* provider can do.
 *
 * Kept apart from {@see PaymentGateway} on purpose. Splitting one customer payment among
 * several sellers at the provider is a capability only some providers have — iyzico does,
 * a plain card gateway does not — and folding these methods into the payment contract
 * would force every adapter to implement three methods it can only throw from.
 *
 * When the contracted provider cannot split, RefConcept settles internally instead: the
 * whole payment lands in one account and the seller payable, hold period and payout are
 * ours to run. That fallback is a Phase 16 concern; what matters here is that the
 * architecture does not assume either answer.
 */
interface MarketplaceSettlementGateway
{
    /**
     * Registers a seller with the provider so money can be attributed to them.
     *
     * Called when a seller is approved, not when they apply — the provider's own checks
     * are not a substitute for ours, and registering an application we later reject
     * leaves an account nobody owns.
     */
    public function onboardSeller(Seller $seller): SellerGatewayProfile;

    /**
     * Tells the provider this seller's part of a payment may be paid out.
     *
     * The provider holds each seller's share until it is approved, which is the same
     * shape as our own hold period: goods delivered, return window closed, no dispute.
     */
    public function approveItem(string $externalItemTransactionId): GatewayResult;

    /** Withdraws that approval — a return, a dispute, a cancelled line. */
    public function disapproveItem(string $externalItemTransactionId): GatewayResult;
}

<?php

declare(strict_types=1);

namespace App\Domains\Payments\Contracts;

use App\Domains\Payments\Services\CancelRequest;
use App\Domains\Payments\Services\CancelResult;
use App\Domains\Payments\Services\PaymentProcessor;
use App\Domains\Payments\Services\PaymentRequest;
use App\Domains\Payments\Services\PaymentResult;
use App\Domains\Payments\Services\RefundRequest;
use App\Domains\Payments\Services\RefundResult;
use App\Domains\Payments\Services\WebhookEvent;

/**
 * What every payment provider has to be able to do.
 *
 * Five methods, and nothing else — mandated by 06_SECURITY_PAYMENT_FINANCE_RULES.md and
 * kept narrow on purpose. An adapter translates between one provider's vocabulary and
 * ours. It does not decide whether to retry, does not write to the database, does not
 * work out what a successful payment means for a basket, and does not log. Every one of
 * those lives in {@see PaymentProcessor}, once, so that
 * adding iyzico in Phase 12 cannot quietly introduce a second set of rules about when a
 * payment counts as paid.
 *
 * An adapter never throws for a provider-side refusal. A decline, a timeout and an
 * invalid-signature are all *answers* the processor has to reason about; an exception
 * would throw away the classification that decides whether a retry is safe. It may throw
 * for a programming error — an unsupported currency, a missing credential — because those
 * are ours to fix and must not be retried.
 *
 * Marketplace settlement is a separate capability, not part of this contract: most
 * providers do not have it, and folding it in here would force every adapter to implement
 * methods it cannot honour. See {@see MarketplaceSettlementGateway}.
 */
interface PaymentGateway
{
    /** The `gateway` value stored on a payment intent that selects this adapter. */
    public function name(): string;

    /**
     * Starts a payment.
     *
     * The result says which of three worlds we are in: done, needs the customer to do
     * something (3DS), or refused. It does not say "success" as a boolean, because a
     * payment awaiting 3DS is neither.
     */
    public function createPayment(PaymentRequest $request): PaymentResult;

    /**
     * Asks the provider what actually happened.
     *
     * The authority when our record and reality might disagree — after a timeout, after a
     * customer closed the tab mid-3DS, during reconciliation. Our own database is never
     * the authority on whether a bank took money.
     */
    public function retrievePayment(string $externalId): PaymentResult;

    /** Voids a payment that has not settled yet. */
    public function cancelPayment(CancelRequest $request): CancelResult;

    /** Returns money that has settled. Partial amounts are normal. */
    public function refund(RefundRequest $request): RefundResult;

    /**
     * Turns a raw webhook into an event we recognise, verifying it on the way.
     *
     * Given the raw body rather than a parsed array deliberately: signatures are computed
     * over exact bytes, and re-encoding JSON to check a signature is how a verification
     * quietly starts passing on everything.
     *
     * @param  array<string, list<string>|string>  $headers
     */
    public function parseWebhook(array $headers, string $body): WebhookEvent;
}

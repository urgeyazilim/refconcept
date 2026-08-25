<?php

declare(strict_types=1);

namespace App\Domains\Payments\Gateways;

use App\Domains\Payments\Contracts\PaymentGateway;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Services\CancelRequest;
use App\Domains\Payments\Services\CancelResult;
use App\Domains\Payments\Services\PaymentRequest;
use App\Domains\Payments\Services\PaymentResult;
use App\Domains\Payments\Services\RefundRequest;
use App\Domains\Payments\Services\RefundResult;
use App\Domains\Payments\Services\WebhookEvent;
use Illuminate\Support\Str;

/**
 * A payment provider that behaves like one without being one.
 *
 * Real enough to exercise every path the code has to survive — immediate capture, 3DS,
 * a decline, a network timeout, a webhook arriving twice, a refund — with no network
 * call. That is what lets the payment tests run in the ordinary suite instead of being a
 * thing somebody remembers to run against a sandbox once a release, which is the same as
 * not having them.
 *
 * **The outcome is chosen by the card token**, not by the amount. Tests that key failures
 * off "an amount ending in 13" read like superstition and break the day somebody writes a
 * fixture that happens to cost that; a token named `tok_decline` says what it is for.
 *
 * This gateway is enabled by default in development and disabled wherever real money
 * moves. Its webhook secret has a default value for the same reason: in an environment
 * where it could protect something, it is not switched on.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const NAME = 'fake';

    /** Immediate success — the default when no token is supplied. */
    public const TOKEN_SUCCESS = 'tok_success';

    /** Sends the customer to a 3DS step first. */
    public const TOKEN_3DS = 'tok_3ds';

    /** A refusal the customer cannot fix by trying again. */
    public const TOKEN_DECLINE = 'tok_decline';

    /** A provider that never answered. Retryable, and the dangerous case. */
    public const TOKEN_TIMEOUT = 'tok_timeout';

    /** Authorises but does not capture, so the two-step flow can be exercised. */
    public const TOKEN_AUTHORIZE_ONLY = 'tok_authorize';

    public function name(): string
    {
        return self::NAME;
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $token = $request->paymentToken ?? self::TOKEN_SUCCESS;
        $externalId = 'fake_'.Str::lower((string) Str::ulid());

        return match ($token) {
            self::TOKEN_3DS => new PaymentResult(
                status: PaymentStatus::RequiresAction,
                externalId: $externalId,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                // A stand-in for the bank's own page. The customer is sent here, does
                // whatever the bank asks, and the answer comes back as a webhook — the
                // same shape as every real 3DS flow, including the part where the browser
                // may never come back at all.
                redirectUrl: rtrim((string) config('app.url'), '/')
                    .'/api/v1/payments/fake/'.$externalId.'/challenge',
                raw: ['token' => $token, 'simulated' => true],
            ),

            self::TOKEN_DECLINE => new PaymentResult(
                status: PaymentStatus::Failed,
                externalId: $externalId,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                errorCode: 'insufficient_funds',
                errorMessage: 'Kartınızın bakiyesi bu ödeme için yeterli değil.',
                raw: ['token' => $token, 'simulated' => true],
                retryable: false,
            ),

            /*
             * The provider did not answer. Reported as failed-but-retryable rather than
             * as a definite failure, because a timeout means we do not know: the bank may
             * well have taken the money. The processor's job is to ask before assuming,
             * and this is the case that proves it does.
             */
            self::TOKEN_TIMEOUT => new PaymentResult(
                status: PaymentStatus::Failed,
                externalId: $externalId,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                errorCode: 'gateway_timeout',
                errorMessage: 'Ödeme sağlayıcısına ulaşılamadı. Lütfen tekrar deneyin.',
                raw: ['token' => $token, 'simulated' => true],
                retryable: true,
            ),

            self::TOKEN_AUTHORIZE_ONLY => new PaymentResult(
                status: PaymentStatus::Authorized,
                externalId: $externalId,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                raw: ['token' => $token, 'simulated' => true],
            ),

            default => new PaymentResult(
                status: PaymentStatus::Captured,
                externalId: $externalId,
                amountMinor: $request->amountMinor,
                currency: $request->currency,
                raw: ['token' => $token, 'simulated' => true, 'masked_pan' => '5528 **** **** 4682'],
            ),
        };
    }

    /**
     * What the provider says the payment is now.
     *
     * The fake has no memory of its own, so it answers from ours — which is exactly the
     * wrong thing for a real adapter to do and exactly the right thing here: the point of
     * the method under test is that the *processor* asks, not that the fake remembers.
     */
    public function retrievePayment(string $externalId): PaymentResult
    {
        $intent = PaymentIntent::query()
            ->where('gateway', self::NAME)
            ->where('external_id', $externalId)
            ->first();

        if ($intent === null) {
            return new PaymentResult(
                status: PaymentStatus::Failed,
                externalId: $externalId,
                errorCode: 'not_found',
                errorMessage: 'Ödeme bulunamadı.',
            );
        }

        return new PaymentResult(
            status: $intent->status,
            externalId: $externalId,
            amountMinor: $intent->amount_minor,
            currency: $intent->currency,
            raw: ['simulated' => true],
        );
    }

    public function cancelPayment(CancelRequest $request): CancelResult
    {
        return new CancelResult(
            succeeded: true,
            externalId: $request->intent->external_id,
            raw: ['simulated' => true, 'reason' => $request->reason],
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        return new RefundResult(
            succeeded: true,
            amountMinor: $request->amountMinor,
            externalId: 'fake_rf_'.Str::lower((string) Str::ulid()),
            raw: ['simulated' => true, 'reason' => $request->reason],
        );
    }

    /**
     * Verifies and reads one webhook.
     *
     * The signature is an HMAC over the exact bytes received. Not over a re-encoded array:
     * `json_encode(json_decode($body))` is not `$body` — key order and number formatting
     * both move — so verifying the round trip is how a check quietly starts passing on
     * everything.
     *
     * @param  array<string, list<string>|string>  $headers
     */
    public function parseWebhook(array $headers, string $body): WebhookEvent
    {
        $signature = $this->header($headers, 'x-refconcept-signature');
        $expected = hash_hmac('sha256', $body, $this->secret());

        // hash_equals, not ===: a comparison that returns early on the first wrong byte
        // tells an attacker how much of their guess was right.
        $verified = $signature !== null && hash_equals($expected, $signature);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true) ?: [];

        $status = match ((string) ($payload['status'] ?? '')) {
            'captured' => PaymentStatus::Captured,
            'authorized' => PaymentStatus::Authorized,
            'failed' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            'refunded' => PaymentStatus::Refunded,
            default => null,
        };

        return new WebhookEvent(
            gateway: self::NAME,
            signatureVerified: $verified,
            externalEventId: isset($payload['event_id']) ? (string) $payload['event_id'] : null,
            externalPaymentId: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            type: isset($payload['type']) ? (string) $payload['type'] : null,
            status: $status,
            amountMinor: isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            errorCode: isset($payload['error_code']) ? (string) $payload['error_code'] : null,
            errorMessage: isset($payload['error_message']) ? (string) $payload['error_message'] : null,
            payload: $payload,
        );
    }

    /**
     * Signs a body the way this gateway expects to receive it.
     *
     * Used by the simulator endpoint and by the tests, so that a webhook test signs its
     * payload with the same code that verifies it — and a change to either side breaks
     * loudly rather than leaving verification passing on nothing.
     */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret());
    }

    private function secret(): string
    {
        return (string) config('payments.gateways.fake.webhook_secret', 'refconcept-fake-gateway');
    }

    /**
     * @param  array<string, list<string>|string>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (mb_strtolower((string) $key) !== $name) {
                continue;
            }

            return is_array($value) ? ($value[0] ?? null) : $value;
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Services\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The bank, pretended.
 *
 * Two things a real provider does that nothing else in the codebase can stand in for: it
 * shows the customer a 3DS page we do not control, and it later posts a signed webhook
 * from outside. Both are simulated here so the whole flow — redirect out, come back,
 * webhook arrives, possibly twice — can be exercised by an ordinary test instead of by a
 * person clicking through a sandbox.
 *
 * **Refuses to exist unless the fake gateway is enabled.** An environment that takes real
 * money does not have it switched on, so these routes 404 there — which matters, because
 * an endpoint that can confirm a payment by asking politely is the worst thing this
 * codebase could ship by accident.
 */
final class FakeGatewayController
{
    public function __construct(
        private readonly FakePaymentGateway $gateway,
        private readonly WebhookInbox $inbox,
    ) {}

    /**
     * The stand-in for a bank's 3DS page.
     *
     * Deliberately plain HTML with two buttons. It is not part of the product, it is a
     * prop — and making it look like a real bank page would be a phishing template
     * sitting in the repository.
     */
    public function challenge(string $externalId): Response
    {
        $this->assertEnabled();

        $intent = $this->intent($externalId);

        $amount = number_format($intent->amount_minor / 100, 2, ',', '.');
        $returnUrl = rtrim((string) config('refconcept.urls.storefront'), '/')
            .'/checkout/return?payment='.$intent->getKey();

        $html = view('payments.fake-challenge', [
            'externalId' => $externalId,
            'amount' => $amount,
            'currency' => $intent->currency,
            'returnUrl' => $returnUrl,
            'completeUrl' => url('/api/v1/payments/fake/'.$externalId.'/complete'),
        ])->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Sends the webhook a real provider would have sent.
     *
     * `deliveries` exists so a test can ask for the same event three times and prove that
     * the money moves once. That is the single most valuable thing this endpoint does:
     * duplicate delivery is not an edge case in payments, it is Tuesday.
     */
    public function complete(Request $request, string $externalId): JsonResponse
    {
        $this->assertEnabled();

        $intent = $this->intent($externalId);

        $validated = $request->validate([
            'outcome' => ['sometimes', 'string', 'in:captured,authorized,failed,cancelled'],
            'deliveries' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'amount_minor' => ['sometimes', 'integer', 'min:1'],
            // Lets a test send the same event id twice (a provider retry) or two different
            // ones carrying the same news (a provider with two feeds). Both happen, and
            // they are deduped by different mechanisms.
            'event_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $outcome = $validated['outcome'] ?? 'captured';
        $deliveries = (int) ($validated['deliveries'] ?? 1);

        $payload = [
            'event_id' => $validated['event_id'] ?? 'evt_'.Str::lower((string) Str::ulid()),
            'type' => 'payment.'.$outcome,
            'payment_id' => $externalId,
            'status' => $outcome,
            'amount_minor' => $validated['amount_minor'] ?? $intent->amount_minor,
            'currency' => $intent->currency,
        ];

        if ($outcome === 'failed') {
            $payload['error_code'] = 'do_not_honour';
            $payload['error_message'] = 'Banka ödemeyi onaylamadı.';
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        $results = [];

        for ($i = 0; $i < $deliveries; $i++) {
            $outcomeOfDelivery = $this->inbox->receive(
                FakePaymentGateway::NAME,
                ['x-refconcept-signature' => $this->gateway->sign($body), 'content-type' => 'application/json'],
                $body,
            );

            $results[] = [
                'duplicate' => $outcomeOfDelivery['duplicate'],
                'verified' => $outcomeOfDelivery['verified'],
            ];
        }

        return response()->json([
            'data' => [
                'payment_id' => $externalId,
                'deliveries' => $results,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function intent(string $externalId): PaymentIntent
    {
        $intent = PaymentIntent::query()
            ->where('gateway', FakePaymentGateway::NAME)
            ->where('external_id', $externalId)
            ->first();

        abort_if($intent === null, 404, 'Ödeme bulunamadı.');

        return $intent;
    }

    private function assertEnabled(): void
    {
        abort_unless((bool) config('payments.gateways.fake.enabled', false), 404);
    }
}

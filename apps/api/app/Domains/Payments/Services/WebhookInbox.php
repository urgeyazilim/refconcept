<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Jobs\ProcessPaymentWebhook;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Receiving a webhook without believing it yet.
 *
 * The order of operations is the entire design, and it is the order laid down in
 * 06_SECURITY_PAYMENT_FINANCE_RULES.md: receive, verify, persist, dedupe, queue,
 * acknowledge. Doing the domain work inline instead would mean a slow database turns into
 * a provider retry, which turns into a second delivery of the same event, which — if the
 * first one is still running — turns into a customer credited twice. So this class does
 * almost nothing: it writes a row and answers.
 *
 * Deduplication is on two keys, because providers are inconsistent about giving events an
 * id: the provider's event id where there is one, and a fingerprint of the raw body, which
 * always exists. Both are unique indexes, so the race where two deliveries arrive
 * simultaneously is settled by PostgreSQL rather than by a check-then-insert that both
 * pass.
 *
 * An event that fails signature verification is stored and refused, not discarded. It is
 * either a misconfigured secret — better seen as a row than as a 400 in a log nobody
 * reads — or somebody forging payment confirmations, which is very much worth keeping.
 */
final class WebhookInbox
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * Takes one delivery and, if it is new, queues it.
     *
     * @param  array<string, list<string>|string>  $headers
     * @return array{event: PaymentWebhookEvent|null, duplicate: bool, verified: bool}
     */
    public function receive(string $gatewayName, array $headers, string $body): array
    {
        $gateway = $this->gateways->forExistingPayment($gatewayName);
        $parsed = $gateway->parseWebhook($headers, $body);

        $fingerprint = hash('sha256', $body);

        $existing = $this->findExisting($gatewayName, $parsed->externalEventId, $fingerprint);

        if ($existing !== null) {
            // Already have it. Answering 200 is deliberate: a provider told that a
            // duplicate failed will keep resending it forever.
            return ['event' => $existing, 'duplicate' => true, 'verified' => $existing->signature_verified];
        }

        try {
            $event = PaymentWebhookEvent::query()->create([
                'gateway' => $gatewayName,
                'event_type' => $parsed->type,
                'external_event_id' => $parsed->externalEventId,
                'body_fingerprint' => $fingerprint,
                'signature_verified' => $parsed->signatureVerified,
                'headers' => $this->safeHeaders($headers),
                'payload' => $parsed->payload,
                'status' => $parsed->signatureVerified ? 'received' : 'failed',
                'payment_intent_id' => $this->resolveIntentId($gatewayName, $parsed),
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            /*
             * Two deliveries of the same event arrived at the same instant and the unique
             * index settled it. That is the index doing its job, not an error — the loser
             * simply reports the duplicate it lost to.
             */
            $existing = $this->findExisting($gatewayName, $parsed->externalEventId, $fingerprint);

            if ($existing === null) {
                throw $e;
            }

            return ['event' => $existing, 'duplicate' => true, 'verified' => $existing->signature_verified];
        }

        if (! $parsed->signatureVerified) {
            $event->forceFill([
                'error_message' => 'İmza doğrulanamadı.',
                'processed_at' => now(),
            ])->save();

            Log::warning('Doğrulanamayan ödeme bildirimi alındı.', [
                'gateway' => $gatewayName,
                'event' => $event->getKey(),
            ]);

            return ['event' => $event, 'duplicate' => false, 'verified' => false];
        }

        ProcessPaymentWebhook::dispatch((string) $event->getKey());

        return ['event' => $event, 'duplicate' => false, 'verified' => true];
    }

    private function findExisting(string $gateway, ?string $externalEventId, string $fingerprint): ?PaymentWebhookEvent
    {
        $query = PaymentWebhookEvent::query()->where('gateway', $gateway);

        if ($externalEventId !== null) {
            $byId = (clone $query)->where('external_event_id', $externalEventId)->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        return $query->where('body_fingerprint', $fingerprint)->first();
    }

    private function resolveIntentId(string $gateway, WebhookEvent $parsed): ?string
    {
        if ($parsed->externalPaymentId === null) {
            return null;
        }

        return PaymentIntent::query()
            ->where('gateway', $gateway)
            ->where('external_id', $parsed->externalPaymentId)
            ->value('id');
    }

    /**
     * The headers worth keeping, without the ones that are credentials.
     *
     * A stored `Authorization` header is a stored password, and a webhook table is read
     * by more people than a secret store is. The signature header is kept because
     * verifying a stored event later is a real debugging need and the signature alone
     * grants nothing.
     *
     * @param  array<string, list<string>|string>  $headers
     * @return array<string, string>
     */
    private function safeHeaders(array $headers): array
    {
        $keep = ['content-type', 'user-agent', 'x-refconcept-signature', 'x-request-id', 'x-iyz-signature'];

        $safe = [];

        foreach ($headers as $key => $value) {
            $name = mb_strtolower((string) $key);

            if (! in_array($name, $keep, true)) {
                continue;
            }

            $safe[$name] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        return $safe;
    }
}

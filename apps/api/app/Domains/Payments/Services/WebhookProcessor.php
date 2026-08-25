<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turning a stored webhook into a domain command.
 *
 * Everything here is idempotent, and it has to be at two different levels. The inbox
 * already refuses a second copy of the same delivery — but a provider may also send two
 * *different* events that say the same thing ("captured" from the browser return and
 * "captured" from the nightly reconciliation feed), and those are not duplicates by any
 * fingerprint. The second line of defence is the state machine: applying "captured" to a
 * payment that is already captured is not a transition, so nothing happens twice.
 *
 * The event's own status is claimed with a conditional update rather than a read and a
 * write. Two workers pulling the same job — which happens after a redeploy, or when a
 * job is retried while the original is still finishing — would otherwise both find
 * `received` and both process it.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly PaymentProcessor $processor,
    ) {}

    public function process(PaymentWebhookEvent $event): void
    {
        if (! $this->claim($event)) {
            // Somebody else has it, or it is already done.
            return;
        }

        try {
            $this->apply($event);
        } catch (Throwable $e) {
            $event->forceFill([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 300),
            ])->save();

            throw $e;
        }
    }

    /**
     * Marks the event as ours to work on.
     *
     * A single conditional UPDATE: whoever's statement changes a row owns the event, and
     * every other worker sees zero rows affected and stops. A `SELECT` followed by a
     * `save()` would let two workers both read `received` and both proceed.
     */
    private function claim(PaymentWebhookEvent $event): bool
    {
        $claimed = PaymentWebhookEvent::query()
            ->whereKey($event->getKey())
            ->whereIn('status', ['received', 'failed'])
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $event->refresh();

        return true;
    }

    private function apply(PaymentWebhookEvent $event): void
    {
        $gateway = $this->gateways->forExistingPayment($event->gateway);

        /*
         * Re-read from the stored payload, and the signature is deliberately not
         * re-checked here.
         *
         * It cannot be: a signature is computed over exact bytes, and what is stored is a
         * decoded and re-encoded copy whose key order and number formatting have both
         * moved. Verification happened once, at the door, against the bytes as received —
         * and an event that failed it never reaches this class, because the inbox marks it
         * failed and never queues it.
         */
        $parsed = $gateway->parseWebhook(
            $this->headers($event),
            (string) json_encode($event->payload ?? [], JSON_UNESCAPED_UNICODE),
        );

        if (! $parsed->isActionable()) {
            /*
             * A provider event we have no rule for — a settlement notice, a heartbeat, a
             * type added after we integrated. Ignored rather than failed: retrying it
             * eight times will not make us understand it, and a `failed` row here would
             * bury the events that genuinely need somebody.
             */
            $event->forceFill([
                'status' => 'ignored',
                'processed_at' => now(),
            ])->save();

            return;
        }

        $intent = $this->findIntent($event, $parsed);

        if ($intent === null) {
            /*
             * A payment we have never heard of. Not an error we can fix by retrying, and
             * not something to fail loudly either — the most common cause is a webhook
             * from another environment pointed at this one by a stale configuration.
             */
            $event->forceFill([
                'status' => 'ignored',
                'error_message' => 'Bildirimdeki ödeme bulunamadı.',
                'processed_at' => now(),
            ])->save();

            return;
        }

        /*
         * The provider is the authority, but only about its own payment — so the amount
         * is checked rather than trusted. An event claiming a capture of a different
         * figure is a genuine anomaly: a partial capture we did not ask for, a
         * misrouted event, or a forgery that passed verification because a secret leaked.
         * None of those should quietly become "paid".
         */
        if ($parsed->amountMinor !== null && $parsed->amountMinor > $intent->amount_minor) {
            $event->forceFill([
                'status' => 'failed',
                'error_message' => 'Bildirilen tutar ödeme tutarından büyük.',
                'payment_intent_id' => $intent->getKey(),
                'processed_at' => now(),
            ])->save();

            return;
        }

        $status = $parsed->status;

        if ($status === null) {
            return;
        }

        $this->processor->apply($intent, new PaymentResult(
            status: $status,
            externalId: $parsed->externalPaymentId,
            amountMinor: $parsed->amountMinor ?? $intent->amount_minor,
            currency: $parsed->currency ?? $intent->currency,
            errorCode: $parsed->errorCode,
            errorMessage: $parsed->errorMessage,
            raw: $parsed->payload,
        ));

        $event->forceFill([
            'status' => 'processed',
            'payment_intent_id' => $intent->getKey(),
            'processed_at' => now(),
        ])->save();
    }

    private function findIntent(PaymentWebhookEvent $event, WebhookEvent $parsed): ?PaymentIntent
    {
        if ($event->payment_intent_id !== null) {
            return PaymentIntent::query()->find($event->payment_intent_id);
        }

        return PaymentIntent::query()
            ->where('gateway', $event->gateway)
            ->where('external_id', $parsed->externalPaymentId)
            ->first();
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function headers(PaymentWebhookEvent $event): array
    {
        /** @var array<string, list<string>|string> $headers */
        $headers = $event->headers ?? [];

        return $headers;
    }
}

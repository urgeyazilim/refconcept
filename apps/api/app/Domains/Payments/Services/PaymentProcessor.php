<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The one place a payment's state is allowed to change.
 *
 * Everything that learns something about a payment — the customer's browser coming back
 * from 3DS, a webhook, a reconciliation sweep, an operator's refund — comes through here,
 * and here the same three rules are applied every time:
 *
 *  1. **Lock the row first.** A browser return and a webhook routinely arrive within
 *     milliseconds of each other saying the same thing. Without `FOR UPDATE` both read
 *     "not captured yet", both capture, and the customer's account is credited twice.
 *
 *  2. **Check the transition.** News arrives out of order. A late `failed` for a payment
 *     that has since captured is dropped, deliberately, because the alternative is a
 *     record that says we were not paid while the money sits in the account.
 *
 *  3. **Write a transaction row for everything.** Including the refusals in (2). "We were
 *     told this and ignored it" is exactly the sentence somebody needs six months later.
 *
 * What this class does *not* do is decide what a captured payment means. Crediting a
 * wallet or releasing a basket's stock is {@see CheckoutFulfiller}'s work, called once,
 * from here, at the single moment the transition to captured actually happens — so a
 * webhook delivered four times cannot fulfil four times.
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly CheckoutFulfiller $fulfiller,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sends a prepared intent to its provider and records what came back.
     *
     * The intent exists before the call, with a status of `created`, and that ordering
     * matters: if the process dies mid-call we have a row that says a payment may be in
     * flight, and reconciliation can ask the provider. Creating the row afterwards would
     * lose exactly the payments most worth finding.
     */
    public function start(PaymentIntent $intent, PaymentRequest $request): PaymentIntent
    {
        $gateway = $this->gateways->get($intent->gateway);

        $intent->forceFill(['attempts' => $intent->attempts + 1])->save();

        $result = $gateway->createPayment($request);

        $this->record(
            $intent,
            type: 'sale',
            status: $result->failed() ? 'failed' : ($result->succeeded() ? 'succeeded' : 'pending'),
            amountMinor: $result->amountMinor !== 0 ? $result->amountMinor : $intent->amount_minor,
            result: $result,
            idempotencyKey: $request->idempotencyKey,
            requestFingerprint: $this->fingerprint($request),
        );

        return $this->apply($intent, $result);
    }

    /**
     * Applies what a provider said to the intent, once.
     *
     * The transaction wraps the lock, the transition and the fulfilment together: either
     * the payment is captured *and* the wallet credited, or neither happened. A capture
     * committed without its fulfilment is a customer who paid for nothing, and no amount
     * of retrying afterwards can tell that apart from a customer who has not paid yet.
     */
    public function apply(PaymentIntent $intent, PaymentResult $result): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $result): PaymentIntent {
            $locked = $this->lock($intent);

            if ($result->externalId !== null && $locked->external_id === null) {
                $locked->external_id = $result->externalId;
            }

            if ($result->redirectUrl !== null) {
                $locked->redirect_url = $result->redirectUrl;
            }

            if ($result->raw !== []) {
                $locked->details = $this->redact($result->raw);
            }

            if (! $locked->status->canTransitionTo($result->status)) {
                /*
                 * Not an error, and not silent either. Out-of-order news is normal — a
                 * retried webhook from before a refund, a browser returning after a
                 * timeout was already resolved — and the right response is to keep the
                 * state we have and leave a note saying we were told otherwise.
                 */
                Log::info('Ödeme durum geçişi yok sayıldı.', [
                    'intent' => $locked->getKey(),
                    'from' => $locked->status->value,
                    'to' => $result->status->value,
                ]);

                $locked->save();

                return $locked;
            }

            $before = $locked->status;
            $becameCaptured = $before !== PaymentStatus::Captured
                && $result->status === PaymentStatus::Captured;

            $locked->status = $result->status;

            match ($result->status) {
                PaymentStatus::Authorized => $locked->authorized_at ??= now(),
                PaymentStatus::Captured => $this->stampCapture($locked, $result),
                PaymentStatus::Cancelled => $locked->cancelled_at ??= now(),
                PaymentStatus::Failed => $this->stampFailure($locked, $result),
                default => null,
            };

            $locked->save();

            if ($becameCaptured) {
                // Inside the transaction, exactly once, at the one moment the status
                // actually moved to captured. A webhook delivered four times reaches this
                // line once, because the other three cannot make that transition again.
                $this->fulfiller->fulfil($locked);
            }

            if ($before !== $locked->status) {
                $this->audit->record(
                    action: 'payments.intent.'.$locked->status->value,
                    subject: $locked,
                    changes: ['status' => [$before->value, $locked->status->value]],
                );
            }

            return $locked;
        });
    }

    /**
     * Asks the provider what really happened and applies the answer.
     *
     * The resolution for every "we do not know" — a timeout, a customer who closed the
     * 3DS tab, a reconciliation sweep. Our database is never the authority on whether a
     * bank took money; this is how we stop guessing and ask.
     */
    public function synchronise(PaymentIntent $intent): PaymentIntent
    {
        if ($intent->external_id === null) {
            // Nothing to ask about: the provider never got far enough to name it.
            return $intent;
        }

        $gateway = $this->gateways->forExistingPayment($intent->gateway);
        $result = $gateway->retrievePayment($intent->external_id);

        $this->record(
            $intent,
            type: 'query',
            status: 'succeeded',
            amountMinor: $result->amountMinor,
            result: $result,
        );

        return $this->apply($intent, $result);
    }

    /**
     * Voids a payment that has not settled.
     *
     * Distinct from a refund on purpose: a void leaves nothing on the customer's
     * statement, while a refund puts two lines on it and takes days. Using the wrong one
     * is a support call we caused.
     */
    public function cancel(PaymentIntent $intent, ?string $reason = null): PaymentIntent
    {
        if ($intent->status->isSettled()) {
            // Money has moved; this is a refund's job, and quietly turning one into the
            // other would hide the fact that the caller asked for the wrong thing.
            return $intent;
        }

        $gateway = $this->gateways->forExistingPayment($intent->gateway);

        $result = $gateway->cancelPayment(new CancelRequest(
            intent: $intent,
            reason: $reason,
            idempotencyKey: 'cancel:'.$intent->getKey(),
        ));

        $this->record(
            $intent,
            type: 'cancel',
            status: $result->succeeded ? 'succeeded' : 'failed',
            amountMinor: $intent->amount_minor,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            response: $result->raw,
            idempotencyKey: 'cancel:'.$intent->getKey(),
        );

        if (! $result->succeeded) {
            return $intent;
        }

        return $this->apply($intent, new PaymentResult(
            status: PaymentStatus::Cancelled,
            externalId: $intent->external_id,
            amountMinor: $intent->amount_minor,
            currency: $intent->currency,
        ));
    }

    /**
     * Sends money back.
     *
     * The amount is checked against what is actually left rather than trusted, because a
     * refund larger than the capture is never a decision somebody made on purpose. The
     * database CHECK would catch it too; this catches it with a sentence instead of a
     * constraint violation.
     */
    public function refund(PaymentIntent $intent, int $amountMinor, ?string $reason = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $refundable = $intent->refundableMinor();

        if ($amountMinor <= 0 || $amountMinor > $refundable) {
            throw new InvalidArgumentException(
                sprintf('İade tutarı 1 ile %d arasında olmalı.', $refundable),
            );
        }

        $key = $idempotencyKey ?? 'refund:'.Str::uuid7()->toString();

        /*
         * An already-recorded refund under this key is that refund, not a second one —
         * unless it failed. A failed attempt is not a completed operation: a provider
         * outage is the commonest cause and the customer is owed the money either way, so
         * treating it as done would strand them.
         */
        $existing = PaymentTransaction::query()
            ->where('payment_intent_id', $intent->getKey())
            ->where('type', 'refund')
            ->where('idempotency_key', $key)
            ->where('status', '!=', 'failed')
            ->first();

        if ($existing !== null) {
            return $intent->refresh();
        }

        $gateway = $this->gateways->forExistingPayment($intent->gateway);

        $result = $gateway->refund(new RefundRequest(
            intent: $intent,
            amountMinor: $amountMinor,
            currency: $intent->currency,
            reason: $reason,
            idempotencyKey: $key,
        ));

        $this->record(
            $intent,
            type: 'refund',
            status: $result->succeeded ? ($result->pending ? 'pending' : 'succeeded') : 'failed',
            amountMinor: $amountMinor,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            response: $result->raw,
            externalId: $result->externalId,
            idempotencyKey: $key,
        );

        if (! $result->succeeded || $result->pending) {
            return $intent->refresh();
        }

        return DB::transaction(function () use ($intent, $amountMinor, $reason): PaymentIntent {
            $locked = $this->lock($intent);

            $locked->refunded_minor += $amountMinor;

            $next = $locked->refunded_minor >= $locked->captured_minor
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            if ($locked->status->canTransitionTo($next)) {
                $locked->status = $next;
            }

            $locked->save();

            $this->audit->record(
                action: 'payments.intent.refunded',
                subject: $locked,
                changes: ['refunded_minor' => [$locked->refunded_minor - $amountMinor, $locked->refunded_minor]],
                context: ['amount_minor' => $amountMinor, 'reason' => $reason],
            );

            return $locked;
        });
    }

    // --- internals -----------------------------------------------------------

    private function lock(PaymentIntent $intent): PaymentIntent
    {
        /** @var PaymentIntent $locked */
        $locked = PaymentIntent::query()
            ->whereKey($intent->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $locked;
    }

    private function stampCapture(PaymentIntent $intent, PaymentResult $result): void
    {
        $intent->authorized_at ??= now();
        $intent->captured_at ??= now();

        // The provider's figure, not ours, when it gave one: a partial capture announced
        // as a full one is a real way to hand over goods for less money than we think.
        $intent->captured_minor = $result->amountMinor > 0
            ? min($result->amountMinor, $intent->amount_minor)
            : $intent->amount_minor;

        $intent->failure_code = null;
        $intent->failure_message = null;
    }

    private function stampFailure(PaymentIntent $intent, PaymentResult $result): void
    {
        $intent->failure_code = $result->errorCode;
        $intent->failure_message = $result->errorMessage === null
            ? null
            : Str::limit($result->errorMessage, 197);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function record(
        PaymentIntent $intent,
        string $type,
        string $status,
        int $amountMinor,
        ?PaymentResult $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        array $response = [],
        ?string $externalId = null,
        ?string $idempotencyKey = null,
        ?string $requestFingerprint = null,
    ): PaymentTransaction {
        return PaymentTransaction::query()->create([
            'payment_intent_id' => $intent->getKey(),
            'gateway' => $intent->gateway,
            'type' => $type,
            'status' => $status,
            'amount_minor' => max(0, $amountMinor),
            'currency' => $intent->currency,
            'external_id' => $externalId ?? $result->externalId ?? $intent->external_id,
            'request_fingerprint' => $requestFingerprint,
            'response' => $this->redact($result->raw ?? $response),
            'error_code' => $errorCode ?? $result?->errorCode,
            'error_message' => Str::limit($errorMessage ?? $result->errorMessage ?? '', 197) ?: null,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Removes anything that must never be stored.
     *
     * Belt and braces: adapters are written not to return card data, and this runs anyway.
     * The cost of the check is nothing; the cost of one provider one day echoing a PAN
     * into a field we persisted is the kind of incident that ends a payments integration.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function redact(array $raw): array
    {
        $forbidden = ['pan', 'card_number', 'cardnumber', 'cvv', 'cvc', 'cvv2', 'expiry', 'expire_month', 'expire_year', 'card_holder_name'];

        foreach ($raw as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $forbidden, true)) {
                unset($raw[$key]);

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $raw[$key] = $this->redact($value);
            }
        }

        return $raw;
    }

    /**
     * A stable summary of what we sent, without keeping what we sent.
     *
     * Enough to tell "the same call again" from "a different call" during an
     * investigation, without storing a request body that may have held a token.
     */
    private function fingerprint(PaymentRequest $request): string
    {
        return hash('sha256', implode('|', [
            $request->intent->getKey(),
            $request->amountMinor,
            $request->currency,
            $request->idempotencyKey ?? '',
        ]));
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Enums\PaymentStatus;

/**
 * One thing a provider told us, unprompted.
 *
 * The adapter's job is to answer four questions about a raw request: is it genuinely from
 * the provider, which payment is it about, what is it claiming, and does it have an id we
 * can dedupe on. Everything after that is ours.
 *
 * `signatureVerified` is false rather than an exception when verification fails, because
 * an event that fails verification is still worth storing. It is either a misconfigured
 * secret — which we want to see as a row rather than as a 400 in a log nobody reads — or
 * somebody forging payment confirmations, which we very much want to see. It is stored and
 * refused, not stored and acted upon.
 *
 * `amountMinor` is carried because a webhook that says "captured" without saying how much
 * cannot be reconciled against what we asked for, and a partial capture announced as a
 * full one is a real way to lose money.
 */
final readonly class WebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $gateway,
        public bool $signatureVerified,
        /** The provider's own id for this event, when it has one. */
        public ?string $externalEventId = null,
        /** The provider's id for the *payment*, which is how we find our intent. */
        public ?string $externalPaymentId = null,
        /** The provider's own word for what happened, kept for the record. */
        public ?string $type = null,
        /** What the event claims the payment is now, translated into our vocabulary. */
        public ?PaymentStatus $status = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $payload = [],
    ) {}

    /** Whether this event actually says something about a payment's state. */
    public function isActionable(): bool
    {
        return $this->status !== null && $this->externalPaymentId !== null;
    }
}

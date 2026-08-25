<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Enums\PaymentStatus;

/**
 * What a provider said about one payment.
 *
 * Carries a {@see PaymentStatus} rather than a boolean, because "did it work" has more
 * than two answers and the third one — *we do not know yet* — is the one that causes the
 * damage when it is forced into the other two. A payment awaiting 3DS treated as a failure
 * strands a customer who is about to pay; treated as a success, it ships goods nobody paid
 * for. So the adapter reports the state it actually observed and the processor decides.
 *
 * `raw` is the provider's answer with anything sensitive already removed by the adapter.
 * It exists so a support question three months from now can be answered from our own
 * records instead of a provider dashboard nobody has access to any more.
 */
final readonly class PaymentResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public PaymentStatus $status,
        public ?string $externalId = null,
        public int $amountMinor = 0,
        public string $currency = 'TRY',
        /** Where to send the browser, when the provider wants a step we cannot do here. */
        public ?string $redirectUrl = null,
        /** The provider's own code, kept verbatim — it is what their support asks for. */
        public ?string $errorCode = null,
        /** Already in Turkish and already safe to show a customer. */
        public ?string $errorMessage = null,
        public array $raw = [],
        /**
         * Whether trying again could plausibly work.
         *
         * A network timeout is retryable; "insufficient funds" is not, and retrying it
         * only annoys the customer's bank. The adapter knows which of its own error codes
         * are which, so it says, rather than leaving the processor to guess from strings.
         */
        public bool $retryable = false,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->status, [PaymentStatus::Authorized, PaymentStatus::Captured], true);
    }

    public function needsCustomerAction(): bool
    {
        return $this->status === PaymentStatus::RequiresAction;
    }

    public function failed(): bool
    {
        return in_array($this->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true);
    }
}

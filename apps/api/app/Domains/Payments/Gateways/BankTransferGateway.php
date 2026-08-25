<?php

declare(strict_types=1);

namespace App\Domains\Payments\Gateways;

use App\Domains\Payments\Contracts\PaymentGateway;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Services\BankTransferService;
use App\Domains\Payments\Services\CancelRequest;
use App\Domains\Payments\Services\CancelResult;
use App\Domains\Payments\Services\PaymentRequest;
use App\Domains\Payments\Services\PaymentResult;
use App\Domains\Payments\Services\RefundRequest;
use App\Domains\Payments\Services\RefundResult;
use App\Domains\Payments\Services\WebhookEvent;

/**
 * A payment method with no provider in it.
 *
 * It implements the gateway contract so that a transfer is the same kind of object as a
 * card payment — same state machine, same append-only record, same fulfilment — but there
 * is nobody on the other end of the call. `createPayment()` allocates a reference and
 * returns "waiting for the customer to act"; the money arrives later and a person confirms
 * it through {@see BankTransferService}.
 *
 * Two methods therefore cannot mean anything here and say so rather than pretending:
 * a refund is a manual transfer somebody makes at a bank, and there are no webhooks.
 */
final class BankTransferGateway implements PaymentGateway
{
    public const NAME = 'bank_transfer';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Reports the payment as waiting on the customer.
     *
     * The reference and the account are allocated by the service before this is reached —
     * the adapter's job is to translate, not to decide — so all that happens here is
     * saying which of the three worlds we are in.
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $transfer = BankTransfer::query()
            ->where('payment_intent_id', $request->intent->getKey())
            ->open()
            ->latest('created_at')
            ->first();

        if ($transfer === null) {
            return new PaymentResult(
                status: PaymentStatus::Failed,
                errorCode: 'no_transfer',
                errorMessage: 'Havale kaydı oluşturulamadı.',
            );
        }

        return new PaymentResult(
            status: PaymentStatus::RequiresAction,
            // We are the provider, so our own reference is the external id — and the
            // unique index on (gateway, external_id) is then also the guarantee that no
            // two payments quote the same reference.
            externalId: $transfer->reference,
            amountMinor: $request->amountMinor,
            currency: $request->currency,
            raw: ['method' => 'bank_transfer', 'reference' => $transfer->reference],
        );
    }

    /** Our own record is the only record; there is nobody to ask. */
    public function retrievePayment(string $externalId): PaymentResult
    {
        $transfer = BankTransfer::query()->where('reference', $externalId)->first();

        if ($transfer === null) {
            return new PaymentResult(
                status: PaymentStatus::Failed,
                externalId: $externalId,
                errorCode: 'not_found',
                errorMessage: 'Havale bulunamadı.',
            );
        }

        return new PaymentResult(
            status: $transfer->intent->status ?? PaymentStatus::RequiresAction,
            externalId: $externalId,
            amountMinor: $transfer->expected_minor,
            currency: $transfer->currency,
        );
    }

    /**
     * Cancelling costs nothing: no money has moved, so this is only a note that we have
     * stopped waiting.
     */
    public function cancelPayment(CancelRequest $request): CancelResult
    {
        return new CancelResult(succeeded: true, externalId: $request->intent->external_id);
    }

    /**
     * A bank transfer refund is a person making a transfer at a bank.
     *
     * Reported as `pending` rather than as a success, because saying the money is back
     * when nobody has sent it is the one answer that would mislead a customer into
     * waiting. The instruction is recorded; somebody in finance acts on it.
     */
    public function refund(RefundRequest $request): RefundResult
    {
        return new RefundResult(
            succeeded: true,
            amountMinor: $request->amountMinor,
            pending: true,
            raw: ['method' => 'bank_transfer', 'reason' => $request->reason],
        );
    }

    /**
     * @param  array<string, list<string>|string>  $headers
     */
    public function parseWebhook(array $headers, string $body): WebhookEvent
    {
        // Banks do not call us. If something posts here it is misrouted or hostile, and
        // either way it is not verified and not actionable.
        return new WebhookEvent(gateway: self::NAME, signatureVerified: false);
    }
}

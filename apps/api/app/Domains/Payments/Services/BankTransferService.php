<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Enums\BankTransferStatus;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Models\BankTransfer;
use App\Domains\Payments\Models\PaymentBankAccount;
use App\Domains\Payments\Models\PaymentIntent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything about a bank transfer that a card payment does not need.
 *
 * Three problems, and the whole class is them:
 *
 *  1. **Matching.** A transfer arrives as a line on a statement with a name and an amount.
 *     The reference the customer types is the only thing tying it to an order.
 *  2. **Time.** A card answers in seconds; a transfer takes a day or two. The stock hold
 *     has to survive that or the customer pays for something we have since sold.
 *  3. **Amounts that do not match.** A typo, a bank fee taken in transit, two orders paid
 *     in one go. Handled as named states rather than by an operator quietly deciding what
 *     is close enough.
 */
final class BankTransferService
{
    /**
     * The reference alphabet, with the lookalikes removed.
     *
     * No 0/O, no 1/I/L. The reference is copied by eye from a screen into a banking app,
     * and a character pair that is indistinguishable in one bank's font is a payment
     * nobody can match to an order.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly PaymentProcessor $processor,
        private readonly InventoryLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Allocates a reference and an account, and stretches the clock.
     *
     * The hold is extended from the card window of minutes to the transfer window of days,
     * because a customer told their goods are reserved and then losing them overnight has
     * been lied to. That is a real cost — stock held for two days against a payment that
     * may never arrive — which is why the window is configured rather than generous, and
     * why an unpaid transfer is expired promptly.
     *
     * @throws CheckoutRefused
     */
    public function open(PaymentIntent $intent, ?string $bankAccountId = null): BankTransfer
    {
        $account = $this->accountFor($bankAccountId, $intent->currency);

        if ($account === null) {
            throw CheckoutRefused::transferUnavailable();
        }

        return DB::transaction(function () use ($intent, $account): BankTransfer {
            $existing = BankTransfer::query()
                ->where('payment_intent_id', $intent->getKey())
                ->open()
                ->first();

            if ($existing !== null) {
                // Asking twice is a reload, not a second transfer. Quoting a new reference
                // would leave the customer with two and the money matching neither.
                return $existing;
            }

            $window = $this->window();

            $transfer = BankTransfer::query()->create([
                'payment_intent_id' => $intent->getKey(),
                'bank_account_id' => $account->getKey(),
                'reference' => $this->allocateReference(),
                'expected_minor' => $intent->amount_minor,
                'currency' => $intent->currency,
                'expires_at' => now()->addHours($window),
            ]);

            $intent->forceFill(['expires_at' => now()->addHours($window)])->save();

            $this->extendHolds($intent, $window);

            return $transfer;
        });
    }

    /**
     * Records that the customer says they have paid.
     *
     * Moves the transfer to `under_review` — a claim, not a confirmation. Nothing is
     * released until somebody has seen the money in a statement, because a receipt is a
     * picture and pictures are easy to make.
     */
    public function markSubmitted(BankTransfer $transfer): BankTransfer
    {
        if ($transfer->status !== BankTransferStatus::AwaitingTransfer) {
            return $transfer;
        }

        $transfer->forceFill(['status' => BankTransferStatus::UnderReview])->save();

        return $transfer;
    }

    /**
     * Finance says the money arrived.
     *
     * The amount is what the statement says, not what was expected, and the difference
     * decides the outcome:
     *
     *   received  <  expected → short_paid, nothing released, the shortfall stated
     *   received === expected → confirmed
     *   received  >  expected → over_paid, released, the surplus owed back
     *
     * Releasing on a short payment is the tempting mistake. It is also how a marketplace
     * ships goods for less than they cost, one rounded-down transfer at a time.
     *
     * @throws CheckoutRefused
     */
    public function confirm(
        BankTransfer $transfer,
        int $receivedMinor,
        Carbon $valueDate,
        User $operator,
        ?string $note = null,
    ): BankTransfer {
        if ($receivedMinor <= 0) {
            throw CheckoutRefused::transferAmountInvalid();
        }

        $settled = DB::transaction(function () use ($transfer, $receivedMinor, $valueDate, $operator, $note): BankTransfer {
            /** @var BankTransfer $locked */
            $locked = BankTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDecidable()) {
                /*
                 * Two operators on two stale screens. The second is refused with a
                 * sentence rather than allowed through to release an order twice — which
                 * is the duplicate-confirmation rule from
                 * 06_SECURITY_PAYMENT_FINANCE_RULES.md, enforced here and again by a
                 * partial unique index behind it.
                 */
                throw CheckoutRefused::transferAlreadyDecided($locked->status->label());
            }

            $status = match (true) {
                $receivedMinor < $locked->expected_minor => BankTransferStatus::ShortPaid,
                $receivedMinor > $locked->expected_minor => BankTransferStatus::OverPaid,
                default => BankTransferStatus::Confirmed,
            };

            $locked->forceFill([
                'status' => $status,
                'received_minor' => $receivedMinor,
                'value_date' => $valueDate,
                'confirmed_by' => $operator->getKey(),
                'confirmed_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->record(
                action: 'payments.transfer.'.$status->value,
                subject: $locked,
                changes: ['received_minor' => [null, $receivedMinor]],
                context: [
                    'reference' => $locked->reference,
                    'expected_minor' => $locked->expected_minor,
                    'value_date' => $valueDate->toDateString(),
                ],
                reason: $note,
                actor: $operator,
            );

            return $locked;
        });

        if (! $settled->status->isSettled()) {
            // Short paid: the customer sends the difference against the same reference,
            // and this transfer is confirmed then.
            return $settled;
        }

        $intent = $settled->intent;

        if ($intent !== null) {
            /*
             * Through the processor, so a transfer capture takes exactly the same path as
             * a card capture: the same lock, the same transition check, the same
             * fulfilment called once. The amount is capped at what was owed — the surplus
             * of an overpayment is a refund, not a larger sale.
             */
            $this->processor->apply($intent, new PaymentResult(
                status: PaymentStatus::Captured,
                externalId: $settled->reference,
                amountMinor: min($settled->received_minor ?? 0, $settled->expected_minor),
                currency: $settled->currency,
                raw: ['method' => 'bank_transfer', 'value_date' => $settled->value_date?->toDateString()],
            ));
        }

        return $settled->fresh() ?? $settled;
    }

    /**
     * Finance cannot match it, or the customer asked to cancel.
     *
     * A reason is mandatory. An unexplained financial refusal is indistinguishable from a
     * mistake when somebody reads it back six months later.
     *
     * @throws CheckoutRefused
     */
    public function reject(BankTransfer $transfer, User $operator, string $reason): BankTransfer
    {
        return DB::transaction(function () use ($transfer, $operator, $reason): BankTransfer {
            /** @var BankTransfer $locked */
            $locked = BankTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDecidable()) {
                throw CheckoutRefused::transferAlreadyDecided($locked->status->label());
            }

            $locked->forceFill([
                'status' => BankTransferStatus::Rejected,
                'confirmed_by' => $operator->getKey(),
                'confirmed_at' => now(),
                'decision_note' => $reason,
            ])->save();

            $intent = $locked->intent;

            if ($intent !== null) {
                $this->processor->apply($intent, new PaymentResult(
                    status: PaymentStatus::Failed,
                    externalId: $locked->reference,
                    amountMinor: $locked->expected_minor,
                    currency: $locked->currency,
                    errorCode: 'transfer_rejected',
                    errorMessage: 'Havaleniz eşleştirilemedi.',
                ));
            }

            $this->audit->record(
                action: 'payments.transfer.rejected',
                subject: $locked,
                context: ['reference' => $locked->reference],
                reason: $reason,
                actor: $operator,
            );

            return $locked;
        });
    }

    /**
     * Closes transfers nobody paid, and gives the stock back.
     *
     * Run on a schedule. Two days of a sofa being unbuyable against a transfer that never
     * arrived is two days of somebody else being told it is sold out.
     */
    public function expireOverdue(): int
    {
        $overdue = BankTransfer::query()
            ->open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $closed = 0;

        foreach ($overdue as $transfer) {
            DB::transaction(function () use ($transfer): void {
                $transfer->forceFill(['status' => BankTransferStatus::Expired])->save();

                $intent = $transfer->intent;

                if ($intent !== null && $intent->status->isOpen()) {
                    $this->processor->apply($intent, new PaymentResult(
                        status: PaymentStatus::Expired,
                        externalId: $transfer->reference,
                        amountMinor: $transfer->expected_minor,
                        currency: $transfer->currency,
                    ));
                }

                $this->releaseHolds($intent);
            });

            $closed++;
        }

        return $closed;
    }

    /**
     * The accounts a customer may pay into.
     *
     * @return Collection<int, PaymentBankAccount>
     */
    public function accounts(string $currency = 'TRY'): Collection
    {
        return PaymentBankAccount::query()
            ->active()
            ->where('currency', $currency)
            ->get();
    }

    // --- internals -----------------------------------------------------------

    /**
     * Gives back what an expired transfer was holding.
     *
     * Done here rather than left to the checkout sweeper, and that is the point: the two
     * have separate clocks, and a transfer's deadline is the one the customer was told.
     * Waiting for a second timer to agree would keep a sofa off the market after we had
     * already decided nobody was going to pay for it.
     */
    private function releaseHolds(?PaymentIntent $intent): void
    {
        $session = $intent?->session;

        if ($session === null) {
            return;
        }

        if ($session->cart_id !== null) {
            foreach ($this->stock->reservationsFor('cart', (string) $session->cart_id) as $reservation) {
                $this->stock->release($reservation);
            }
        }

        $session->forceFill([
            'status' => CheckoutStatus::Expired,
            'expires_at' => now(),
        ])->save();
    }

    private function accountFor(?string $id, string $currency): ?PaymentBankAccount
    {
        if ($id !== null) {
            return PaymentBankAccount::query()
                ->active()
                ->where('currency', $currency)
                ->whereKey($id)
                ->first();
        }

        return PaymentBankAccount::query()->active()->where('currency', $currency)->first();
    }

    /**
     * A reference that is unique and readable.
     *
     * Retried on collision rather than assumed unique: at four groups of four from a
     * 31-character alphabet the odds are remote, but "remote" is not a guarantee and the
     * unique index would turn one into a 500 for a customer trying to pay.
     */
    private function allocateReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'RC-'.$this->group().'-'.$this->group().'-'.$this->group();

            if (! BankTransfer::query()->where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Havale referansı üretilemedi.');
    }

    private function group(): string
    {
        $out = '';

        for ($i = 0; $i < 4; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }

    /**
     * Stretches this checkout's stock holds to the transfer window.
     *
     * The hold was taken for the card window when checkout opened; choosing a transfer
     * changes how long the customer needs, not what they are buying.
     */
    private function extendHolds(PaymentIntent $intent, int $hours): void
    {
        $session = $intent->session;

        if ($session === null || $session->cart_id === null) {
            return;
        }

        $this->stock->extendHolds('cart', (string) $session->cart_id, $hours * 3600);

        $session->forceFill(['expires_at' => now()->addHours($hours)])->save();
    }

    private function window(): int
    {
        return (int) config('payments.gateways.bank_transfer.window_hours', 48);
    }
}

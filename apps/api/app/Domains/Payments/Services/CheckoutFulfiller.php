<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Commerce\Enums\CartStatus;
use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Enums\CheckoutPurpose;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Domains\Payments\Models\CheckoutSession;
use App\Domains\Payments\Models\PaymentIntent;
use Illuminate\Support\Facades\Log;

/**
 * What a captured payment actually means.
 *
 * Called from exactly one place — {@see PaymentProcessor::apply()}, inside the same
 * transaction, at the single moment an intent transitions to captured. That is what makes
 * it safe for a provider to deliver the same webhook four times: three of those cannot
 * make the transition, so three of them never reach here.
 *
 * It is nonetheless written to be idempotent a second time over, because "called once" is
 * a property of today's callers and the cost of being wrong is a customer credited twice.
 * The credit ledger dedupes on a reference derived from the intent id; the stock
 * consumption checks the cart's state first.
 *
 * Orders are Phase 15. Until then a paid basket consumes its stock hold and is marked
 * `ordered` — the seam where order creation will hook in, deliberately left as one call
 * rather than spread through the payment code.
 */
final class CheckoutFulfiller
{
    public function __construct(
        private readonly CreditLedger $credits,
        private readonly InventoryLedger $stock,
    ) {}

    public function fulfil(PaymentIntent $intent): void
    {
        $session = $intent->session;

        if ($session === null) {
            // Should be impossible — the column is not nullable — but a payment captured
            // against nothing is exactly the sort of thing worth a loud line rather than
            // a fatal error inside a payment transaction.
            Log::error('Tahsil edilen ödemenin oturumu bulunamadı.', ['intent' => $intent->getKey()]);

            return;
        }

        match ($session->purpose) {
            CheckoutPurpose::Credits => $this->grantCredits($session, $intent),
            CheckoutPurpose::Cart => $this->settleCart($session),
        };

        $session->forceFill([
            'status' => CheckoutStatus::Paid,
            'completed_at' => $session->completed_at ?? now(),
        ])->save();
    }

    /**
     * Puts the credits in the wallet.
     *
     * The reference is derived from the intent, and the ledger returns the existing
     * transaction when it has seen that reference before. That is the whole duplicate
     * defence for E2E-03: the same payment confirmed four times loads credits once, and
     * it holds even if the four arrive concurrently, because the ledger looks the
     * reference up inside its own locked transaction.
     */
    private function grantCredits(CheckoutSession $session, PaymentIntent $intent): void
    {
        $package = $session->creditPackage;
        $user = $session->user;

        if ($package === null || $user === null) {
            Log::error('Kredi paketi ödemesi eksik veri ile tamamlandı.', [
                'session' => $session->getKey(),
            ]);

            return;
        }

        $expiresAt = $package->validity_days === null
            ? null
            : now()->addDays($package->validity_days);

        $this->credits->grant(
            user: $user,
            credits: $package->credits,
            source: CreditLotSource::Purchase,
            description: $package->name.' paketi satın alındı',
            reference: 'payment-intent:'.$intent->getKey(),
            expiresAt: $expiresAt,
            origin: $intent,
        );

        /*
         * Bonus credits are a separate lot with their own reference.
         *
         * Separate because the customer paid for one and was given the other, and that
         * distinction has to survive into a refund: giving back what somebody paid for
         * should not also claw back a gift, and merging the two makes the question
         * unanswerable.
         */
        if ($package->bonus_credits > 0) {
            $this->credits->grant(
                user: $user,
                credits: $package->bonus_credits,
                source: CreditLotSource::Promotion,
                description: $package->name.' paketi hediye kredisi',
                reference: 'payment-intent-bonus:'.$intent->getKey(),
                expiresAt: $expiresAt,
                origin: $intent,
            );
        }
    }

    /**
     * Turns the stock hold into a stock movement and closes the basket.
     *
     * Consuming rather than releasing: the goods have been paid for and must leave the
     * shelf. Leaving the reservation to expire would put sold goods back on sale fifteen
     * minutes later, which is the one stock bug a marketplace cannot explain away.
     */
    private function settleCart(CheckoutSession $session): void
    {
        $cart = $session->cart;

        if ($cart === null) {
            Log::error('Sepet ödemesi sepetsiz tamamlandı.', ['session' => $session->getKey()]);

            return;
        }

        if ($cart->status === CartStatus::Ordered) {
            // Already done. The second caller is a duplicate, and consuming a reservation
            // twice would take the stock down twice.
            return;
        }

        foreach ($this->stock->reservationsFor('cart', (string) $cart->getKey()) as $reservation) {
            $this->stock->consume($reservation);
        }

        $cart->forceFill(['status' => CartStatus::Ordered])->save();
    }
}

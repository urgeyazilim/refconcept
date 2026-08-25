<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Enums\CheckoutPurpose;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Models\CheckoutSession;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\GatewayRegistry;
use App\Domains\Payments\Services\PaymentProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying.
 *
 * Like the cart before it, no session id appears in the routes that open or read a
 * checkout: `/checkout` is always *your* live session. Ids appear only where one has to —
 * a payment being asked after — and are checked against the caller before anything is
 * said about them.
 *
 * Everything here is `no-store`. A checkout page holds an address, a basket and a total;
 * a shared computer with a back button should not be able to show the last customer's.
 */
final class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentProcessor $processor,
        private readonly GatewayRegistry $gateways,
    ) {}

    /**
     * The live session, if there is one.
     *
     * `purpose` defaults to the basket because that is what "checkout" means to almost
     * everybody, but it has to be askable: buying credits mid-checkout is a reasonable
     * thing to do, so a customer can have one of each and reading the wrong one would show
     * them a total that belongs to the other.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'purpose' => ['sometimes', 'string', 'in:cart,credits'],
        ]);

        $session = $this->checkout->liveSession(
            $user,
            CheckoutPurpose::from($validated['purpose'] ?? 'cart'),
        );

        if ($session === null) {
            return $this->json(['data' => null]);
        }

        return $this->json(['data' => $this->sessionArray($session)]);
    }

    /**
     * Opens the basket checkout and takes the stock hold.
     *
     * Idempotent: pressing it twice returns the same session rather than starting a second
     * one and holding the stock twice over.
     */
    public function start(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'shipping_address_id' => ['sometimes', 'nullable', 'uuid'],
            'billing_address_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $session = $this->checkout->openCart($user, [
            'shipping_address_id' => $validated['shipping_address_id'] ?? null,
            'billing_address_id' => $validated['billing_address_id'] ?? null,
        ]);

        return $this->json(['data' => $this->sessionArray($session)], 201);
    }

    /** Opens a checkout for a credit package. */
    public function startCredits(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'package_id' => ['required', 'uuid'],
        ]);

        $package = CreditPackage::query()->findOrFail($validated['package_id']);

        $session = $this->checkout->openCredits($user, $package);

        return $this->json(['data' => $this->sessionArray($session)], 201);
    }

    /**
     * Starts a payment against the live session.
     *
     * The card token comes from the provider's own hosted form; no card number, expiry or
     * CVV is accepted here or anywhere else in this codebase. That is not caution, it is
     * the line between being in PCI-DSS scope and not.
     */
    public function pay(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'purpose' => ['sometimes', 'string', 'in:cart,credits'],
            'gateway' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_token' => ['sometimes', 'nullable', 'string', 'max:191'],
            'bank_account_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $purpose = CheckoutPurpose::from($validated['purpose'] ?? 'cart');
        $session = $this->checkout->liveSession($user, $purpose);

        if ($session === null) {
            throw CheckoutRefused::notYours();
        }

        $intent = $this->checkout->pay(
            $session,
            $validated['gateway'] ?? null,
            $validated['payment_token'] ?? null,
            $request->ip(),
            $request->header('Idempotency-Key'),
            $validated['bank_account_id'] ?? null,
        );

        return $this->json([
            'data' => [
                'payment' => $intent->toCustomerArray(),
                'session' => $this->sessionArray($session->fresh() ?? $session),
            ],
        ], 201);
    }

    /**
     * What became of a payment.
     *
     * Asks the provider when our own record says the answer is not in yet. A customer
     * returning from 3DS reaches this before the webhook does about half the time, and
     * telling them "still processing" when the bank already said yes is how a page ends
     * up polling forever.
     */
    public function payment(Request $request, PaymentIntent $intent): JsonResponse
    {
        $user = $this->user($request);

        abort_unless($intent->user_id === $user->getKey(), 404);

        if ($intent->status->isOpen()) {
            $intent = $this->processor->synchronise($intent);
        }

        return $this->json([
            'data' => [
                'payment' => $intent->toCustomerArray(),
                'session' => $intent->session === null ? null : $this->sessionArray($intent->session),
            ],
        ]);
    }

    /** Backs out of a checkout and hands the stock back. */
    public function cancel(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $purpose = CheckoutPurpose::from((string) $request->input('purpose', 'cart'));
        $session = $this->checkout->liveSession($user, $purpose);

        if ($session === null) {
            return $this->json(['data' => null]);
        }

        $this->checkout->cancel($session);

        return $this->json(['data' => $this->sessionArray($session->fresh() ?? $session)]);
    }

    /** Which providers a customer may actually pay through. */
    public function methods(): JsonResponse
    {
        return $this->json([
            'data' => array_map(
                fn (string $name): array => [
                    'gateway' => $name,
                    'is_default' => $name === (string) config('payments.default'),
                ],
                $this->gateways->available(),
            ),
        ]);
    }

    // --- internals -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function sessionArray(CheckoutSession $session): array
    {
        $intent = $session->liveIntent() ?? $session->paidIntent();

        return [
            'id' => $session->id,
            'purpose' => $session->purpose->value,
            'status' => $session->status->value,
            'status_label' => $session->status->label(),
            'currency' => $session->currency,
            'totals' => [
                'subtotal_minor' => $session->subtotal_minor,
                'discount_minor' => $session->discount_minor,
                'shipping_minor' => $session->shipping_minor,
                'tax_minor' => $session->tax_minor,
                'grand_total_minor' => $session->grand_total_minor,
            ],
            'lines' => $session->lines ?? [],
            'shipping_address' => $session->shipping_address,
            'billing_address' => $session->billing_address,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'payment' => $intent?->toCustomerArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}

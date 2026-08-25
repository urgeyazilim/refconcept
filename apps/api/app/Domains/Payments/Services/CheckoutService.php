<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Commerce\Models\Cart;
use App\Domains\Commerce\Models\CartItem;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Payments\Enums\CheckoutPurpose;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Models\CheckoutSession;
use App\Domains\Payments\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;

/**
 * Opening, pricing and closing the window in which a customer pays.
 *
 * The service exists to answer one question honestly: *what, exactly, is this person
 * paying for?* Between pressing "pay" and the bank answering there is a redirect, a 3DS
 * page and often several minutes, and in those minutes the seller can reprice, the
 * customer can empty the basket in another tab, and the address book can be edited. So
 * the session copies the numbers and the address text in, and from then on the price is
 * the price.
 *
 * The session's clock is the stock hold's clock — fifteen minutes, the same fifteen — for
 * a reason that only shows up when they differ. A session outliving its hold takes money
 * for goods already given away; a hold outliving its session keeps goods off the market
 * for a checkout nobody will finish.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly GatewayRegistry $gateways,
        private readonly PaymentProcessor $processor,
    ) {}

    /**
     * Opens (or re-opens) the basket checkout for a customer.
     *
     * Idempotent by design: pressing "checkout" twice, or reloading the page mid-flow,
     * returns the same session rather than starting a second one and taking a second
     * stock hold. The partial unique index in the schema is what makes that a guarantee
     * rather than a hope.
     *
     * @param  array{shipping_address_id?: string|null, billing_address_id?: string|null}  $input
     *
     * @throws CheckoutRefused
     */
    public function openCart(User $user, array $input): CheckoutSession
    {
        $shipping = $this->addressFor($user, $input['shipping_address_id'] ?? null);

        if ($shipping === null) {
            throw CheckoutRefused::noAddress();
        }

        // Billing defaults to shipping, because for most people it is, and asking twice
        // for the same answer is a step that loses customers for nothing.
        $billing = $this->addressFor($user, $input['billing_address_id'] ?? null) ?? $shipping;

        /*
         * Takes the stock hold and refuses if anything moved. This is the same call the
         * cart page makes, deliberately: there is one definition of "may this basket be
         * paid for", and it lives in CartService rather than being restated here where it
         * could drift.
         */
        $outcome = $this->carts->beginCheckout($user);

        /** @var Cart $cart */
        $cart = $outcome['cart'];

        if ($outcome['issues'] !== []) {
            $blocking = array_filter(
                $outcome['issues'],
                static fn (array $issue): bool => $issue['issue']->blocksCheckout(),
            );

            if ($blocking !== []) {
                throw CheckoutRefused::priceMoved();
            }
        }

        $cart->loadMissing(['items.product', 'items.sku', 'items.seller']);

        return DB::transaction(function () use ($user, $cart, $shipping, $billing): CheckoutSession {
            $session = $this->liveSession($user, CheckoutPurpose::Cart);

            $session ??= new CheckoutSession([
                'user_id' => $user->getKey(),
                'purpose' => CheckoutPurpose::Cart->value,
            ]);

            $session->forceFill([
                'user_id' => $user->getKey(),
                'purpose' => CheckoutPurpose::Cart,
                'status' => CheckoutStatus::Open,
                'cart_id' => $cart->getKey(),
                'credit_package_id' => null,
                'shipping_address' => $this->snapshot($shipping),
                'billing_address' => $this->snapshot($billing),
                'currency' => $cart->currency,
                'subtotal_minor' => $cart->subtotalMinor(),
                'discount_minor' => 0,
                // Shipping is Phase 17. Zero rather than an invented figure: a total that
                // changes after the customer agreed to it is the failure this whole
                // mechanism exists to prevent.
                'shipping_minor' => 0,
                'tax_minor' => $cart->taxMinor(),
                'grand_total_minor' => $cart->subtotalMinor(),
                'lines' => $this->lineSnapshot($cart),
                'expires_at' => now()->addSeconds($this->sessionTtl()),
            ])->save();

            return $session;
        });
    }

    /**
     * Opens a checkout for a credit package.
     *
     * No cart, no stock, no address that anything is shipped to — but the same session,
     * the same intent and the same webhook path, because a payment that took a different
     * route through the code would need its own duplicate defence and would eventually
     * get one that was subtly weaker.
     *
     * @throws CheckoutRefused
     */
    public function openCredits(User $user, CreditPackage $package): CheckoutSession
    {
        if (! $package->is_active) {
            throw CheckoutRefused::packageUnavailable();
        }

        return DB::transaction(function () use ($user, $package): CheckoutSession {
            $session = $this->liveSession($user, CheckoutPurpose::Credits);

            $session ??= new CheckoutSession([
                'user_id' => $user->getKey(),
                'purpose' => CheckoutPurpose::Credits->value,
            ]);

            $session->forceFill([
                'user_id' => $user->getKey(),
                'purpose' => CheckoutPurpose::Credits,
                'status' => CheckoutStatus::Open,
                'cart_id' => null,
                'credit_package_id' => $package->getKey(),
                'currency' => $package->currency,
                'subtotal_minor' => $package->price_minor,
                'discount_minor' => 0,
                'shipping_minor' => 0,
                // Credits are a service; KDV is contained in the listed price the same way
                // it is for goods, at the standard rate.
                'tax_minor' => (int) round($package->price_minor * 2000 / 12000),
                'grand_total_minor' => $package->price_minor,
                'lines' => [[
                    'type' => 'credit_package',
                    'code' => $package->code,
                    'name' => $package->name,
                    'credits' => $package->credits,
                    'bonus_credits' => $package->bonus_credits,
                    'quantity' => 1,
                    'unit_price_minor' => $package->price_minor,
                    'line_total_minor' => $package->price_minor,
                ]],
                'expires_at' => now()->addSeconds($this->sessionTtl()),
            ])->save();

            return $session;
        });
    }

    /**
     * Starts a payment attempt against a session.
     *
     * The intent row is written *before* the provider is called. If the process dies
     * mid-call we are left with a record saying a payment may be in flight, and
     * reconciliation can ask the provider what became of it — whereas creating the row
     * afterwards loses exactly the payments most worth finding.
     *
     * @throws CheckoutRefused
     */
    public function pay(CheckoutSession $session, ?string $gatewayName, ?string $paymentToken, ?string $clientIp = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertPayable($session);

        $gateway = $gatewayName === null
            ? $this->gateways->default()
            : $this->gateways->get($gatewayName);

        $intent = DB::transaction(function () use ($session, $gateway): PaymentIntent {
            $live = $session->liveIntent();

            if ($live !== null) {
                /*
                 * An attempt is already open. Returned rather than refused when it has not
                 * gone anywhere yet — a double-clicked button is not an error — but a
                 * payment the customer is part-way through at their bank is left alone,
                 * because starting a second one is how somebody pays twice.
                 */
                if ($live->status === PaymentStatus::Created) {
                    return $live;
                }

                throw CheckoutRefused::paymentInFlight();
            }

            $session->forceFill(['status' => CheckoutStatus::AwaitingPayment])->save();

            return PaymentIntent::query()->create([
                'checkout_session_id' => $session->getKey(),
                'user_id' => $session->user_id,
                'gateway' => $gateway->name(),
                'amount_minor' => $session->grand_total_minor,
                'currency' => $session->currency,
                'expires_at' => now()->addSeconds((int) config('payments.timings.intent_ttl_seconds', 1800)),
            ]);
        });

        $result = $this->processor->start($intent, new PaymentRequest(
            intent: $intent,
            amountMinor: $intent->amount_minor,
            currency: $intent->currency,
            paymentToken: $paymentToken,
            basket: $session->lines ?? [],
            buyer: $this->buyer($session),
            shippingAddress: $session->shipping_address ?? [],
            billingAddress: $session->billing_address ?? [],
            returnUrl: rtrim((string) config('refconcept.urls.storefront'), '/').'/checkout/return',
            idempotencyKey: $idempotencyKey,
            clientIp: $clientIp,
        ));

        $this->reflect($session->fresh() ?? $session, $result);

        return $result;
    }

    /**
     * Gives up on a checkout and hands the stock back.
     *
     * Released immediately rather than left to expire, because fifteen minutes of a sofa
     * being unbuyable for no reason is fifteen minutes of somebody else being told it is
     * sold out.
     */
    public function cancel(CheckoutSession $session): CheckoutSession
    {
        if ($session->status->isFinished()) {
            return $session;
        }

        $live = $session->liveIntent();

        if ($live !== null && $live->status !== PaymentStatus::Created) {
            throw CheckoutRefused::paymentInFlight();
        }

        return DB::transaction(function () use ($session): CheckoutSession {
            if ($session->purpose === CheckoutPurpose::Cart && $session->user !== null) {
                $this->carts->abandonCheckout($session->user);
            }

            $session->forceFill(['status' => CheckoutStatus::Cancelled])->save();

            return $session;
        });
    }

    /**
     * Closes sessions whose time is up and returns the stock.
     *
     * Run on a schedule. A session left `awaiting_payment` forever is not only untidy: the
     * partial unique index means the customer cannot start a new checkout while it exists,
     * so an abandoned payment would lock somebody out of buying anything.
     */
    public function expireOverdue(): int
    {
        $sessions = CheckoutSession::query()
            ->live()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $closed = 0;

        foreach ($sessions as $session) {
            $live = $session->liveIntent();

            if ($live !== null && $live->status !== PaymentStatus::Created) {
                /*
                 * Somebody is at their bank's 3DS page. Left alone: a customer coming back
                 * to "your payment expired" while the bank believes it succeeded is worse
                 * than a session that lives a few minutes longer than advertised.
                 */
                continue;
            }

            DB::transaction(function () use ($session): void {
                if ($session->purpose === CheckoutPurpose::Cart && $session->user !== null) {
                    $this->carts->abandonCheckout($session->user);
                }

                $session->forceFill(['status' => CheckoutStatus::Expired])->save();
            });

            $closed++;
        }

        return $closed;
    }

    /** The customer's live session for a purpose, if there is one. */
    public function liveSession(User $user, CheckoutPurpose $purpose): ?CheckoutSession
    {
        return CheckoutSession::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose->value)
            ->whereIn('status', [
                CheckoutStatus::Open->value,
                CheckoutStatus::AwaitingPayment->value,
                CheckoutStatus::Failed->value,
            ])
            ->latest('created_at')
            ->first();
    }

    // --- internals -----------------------------------------------------------

    /**
     * @throws CheckoutRefused
     */
    private function assertPayable(CheckoutSession $session): void
    {
        if ($session->status === CheckoutStatus::Paid) {
            throw CheckoutRefused::alreadyPaid();
        }

        if (! $session->status->acceptsPayment()) {
            throw CheckoutRefused::sessionClosed();
        }

        if ($session->hasExpired()) {
            throw CheckoutRefused::sessionExpired();
        }

        if ($session->grand_total_minor <= 0) {
            throw CheckoutRefused::emptyCart();
        }
    }

    /**
     * Moves the session in step with its payment.
     *
     * A failed attempt leaves the session `failed` rather than closed, because the
     * overwhelmingly common cause is a card the customer can simply try again with, and
     * throwing away the price snapshot would make them start over — at whatever the prices
     * are by then.
     */
    private function reflect(CheckoutSession $session, PaymentIntent $intent): void
    {
        // A capture is the fulfiller's business and it has already marked the session
        // paid; touching it here would be a second writer for one fact.
        if ($intent->status->isSettled()) {
            return;
        }

        $status = match (true) {
            $intent->status === PaymentStatus::Failed => CheckoutStatus::Failed,
            $intent->status === PaymentStatus::Cancelled => CheckoutStatus::Failed,
            default => CheckoutStatus::AwaitingPayment,
        };

        if ($session->status !== $status) {
            $session->forceFill(['status' => $status])->save();
        }
    }

    private function addressFor(User $user, ?string $id): ?UserAddress
    {
        if ($id === null) {
            return UserAddress::query()
                ->where('user_id', $user->getKey())
                ->where('is_default_shipping', true)
                ->first();
        }

        $address = UserAddress::query()
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->first();

        if ($address === null) {
            throw CheckoutRefused::addressNotYours();
        }

        return $address;
    }

    /**
     * The address as text, severed from the address book.
     *
     * Everything an invoice or a courier label needs, and nothing that changes when the
     * customer edits their saved address next month.
     *
     * @return array<string, mixed>
     */
    private function snapshot(UserAddress $address): array
    {
        return [
            'id' => $address->getKey(),
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'country_code' => $address->country_code,
            'city' => $address->city,
            'district' => $address->district,
            'neighbourhood' => $address->neighbourhood,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'postal_code' => $address->postal_code,
        ];
    }

    /**
     * The basket as it stood, line by line.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineSnapshot(Cart $cart): array
    {
        return $cart->items->map(static fn (CartItem $item): array => [
            'type' => 'product',
            'sku_id' => $item->sku_id,
            'product_id' => $item->product_id,
            'seller_id' => $item->seller_id,
            'name' => $item->product->name ?? '',
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unit_price_minor,
            'line_total_minor' => $item->lineTotalMinor(),
            'tax_rate_bps' => $item->tax_rate_bps,
            'tax_minor' => $item->taxMinor(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(CheckoutSession $session): array
    {
        $user = $session->user;

        return [
            'id' => $session->user_id,
            'email' => $user?->email,
            'name' => $session->shipping_address['recipient_name'] ?? null,
        ];
    }

    private function sessionTtl(): int
    {
        return (int) config('payments.timings.session_ttl_seconds', CartService::CHECKOUT_HOLD_SECONDS);
    }
}

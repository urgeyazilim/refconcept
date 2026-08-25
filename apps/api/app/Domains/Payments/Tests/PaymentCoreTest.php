<?php

declare(strict_types=1);

use App\Domains\Commerce\Enums\CartStatus;
use App\Domains\Commerce\Models\Cart;
use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Models\CreditTransaction;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Models\StockReservation;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Enums\CheckoutPurpose;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\CheckoutService;
use App\Domains\Payments\Services\PaymentProcessor;
use App\Domains\Payments\Services\WebhookInbox;
use App\Domains\Payments\Services\WebhookProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Taking money, and the four ways a payment system quietly loses it.
 *
 * The gate for this phase is duplicates, replays and timeouts, and that is not an
 * arbitrary list — it is the complete set of ways a provider tells us something more than
 * once or not at all. A bank retries a webhook. A customer's browser comes back from 3DS
 * at the same instant the webhook lands. A network call times out with the money already
 * taken. Each of those has exactly one correct behaviour and several plausible wrong ones,
 * and the wrong ones are indistinguishable from working until somebody is charged twice.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->carts = app(CartService::class);
    $this->checkout = app(CheckoutService::class);
    $this->processor = app(PaymentProcessor::class);
    $this->inbox = app(WebhookInbox::class);
    $this->stock = app(InventoryLedger::class);
    $this->gateway = app(FakePaymentGateway::class);

    [$this->seller] = makeApprovedSeller('Ödeme Test A.Ş.', 'odeme-test');

    $this->category = makeCategory('Koltuk', 'koltuk-odeme', 'living_room');

    $this->product = makeProduct($this->seller, $this->category, [
        'name' => 'Ödeme test koltuğu',
        'description' => 'Ödeme testleri için koltuk.',
        'price_minor' => 1_200_000,
        'stock_quantity' => 4,
    ]);

    $this->sku = $this->product->skus->first();

    $this->stock->adjust($this->stock->itemFor($this->sku), 4, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    $this->address = UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'phone' => '+905551112233',
        'city' => 'İstanbul',
        'district' => 'Kadıköy',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
        'is_default_billing' => true,
    ]);

    $this->package = CreditPackage::query()->create([
        'code' => 'starter-odeme',
        'name' => 'Başlangıç',
        'credits' => 100,
        'bonus_credits' => 20,
        'price_minor' => 49_900,
        'currency' => 'TRY',
        'validity_days' => 365,
    ]);
});

// --- the session -------------------------------------------------------------

it('freezes the price the customer agreed to', function (): void {
    $this->carts->add($this->customer, $this->sku, 2);

    $session = $this->checkout->openCart($this->customer, []);

    expect($session->grand_total_minor)->toBe(2_400_000);

    // The seller reprices while the customer is at their bank.
    $this->sku->forceFill(['list_price_minor' => 9_900_000])->save();

    /*
     * The session does not care. This is the entire reason it copies the numbers instead
     * of holding foreign keys: a total that moves between agreeing to it and paying it is
     * the failure everything else here exists to prevent.
     */
    expect($session->fresh()?->grand_total_minor)->toBe(2_400_000);
});

it('copies the address rather than pointing at it', function (): void {
    $this->carts->add($this->customer, $this->sku);

    $session = $this->checkout->openCart($this->customer, []);

    $this->address->forceFill(['address_line1' => 'Başka bir sokak 5'])->save();

    expect($session->fresh()?->shipping_address['address_line1'] ?? null)
        ->toBe('Bağdat Caddesi 100');
});

it('returns the same session rather than holding stock twice', function (): void {
    $this->carts->add($this->customer, $this->sku, 2);

    $first = $this->checkout->openCart($this->customer, []);
    $second = $this->checkout->openCart($this->customer, []);

    expect($second->getKey())->toBe($first->getKey())
        ->and(StockReservation::query()
            ->where('reference_type', 'cart')
            ->where('status', ReservationStatus::Held->value)
            ->sum('quantity'))->toBe(2);
});

it('refuses a checkout with an address that is not the customer own', function (): void {
    $stranger = User::factory()->create();

    $theirs = UserAddress::query()->create([
        'user_id' => $stranger->getKey(),
        'recipient_name' => 'Başkası',
        'city' => 'Ankara',
        'address_line1' => 'Bir cadde 1',
    ]);

    $this->carts->add($this->customer, $this->sku);

    // A 404 rather than a 403: whether a stranger's address exists is not something to
    // confirm to somebody guessing at ids.
    expect(fn () => $this->checkout->openCart($this->customer, ['shipping_address_id' => $theirs->getKey()]))
        ->toThrow(CheckoutRefused::class);
});

// --- capture and fulfilment --------------------------------------------------

it('consumes the stock hold when the money lands', function (): void {
    $this->carts->add($this->customer, $this->sku, 2);

    $session = $this->checkout->openCart($this->customer, []);

    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    expect($intent->status)->toBe(PaymentStatus::Captured)
        ->and($session->fresh()?->status)->toBe(CheckoutStatus::Paid);

    $reservation = StockReservation::query()
        ->where('reference_type', 'cart')
        ->where('reference_id', (string) $session->cart_id)
        ->first();

    /*
     * Consumed, not released. Releasing would put a sold sofa back on the shelf fifteen
     * minutes later — the one stock bug a marketplace cannot explain away.
     */
    expect($reservation?->status)->toBe(ReservationStatus::Consumed)
        ->and($this->stock->sellableFor($this->sku))->toBe(2);

    expect($this->carts->forUser($this->customer)->getKey())
        ->not->toBe($session->cart_id);

    expect(Cart::query()->find($session->cart_id)?->status)
        ->toBe(CartStatus::Ordered);
});

it('does not let a basket be emptied by its own stock hold', function (): void {
    /*
     * A customer takes the last of the stock into checkout and then reloads the page.
     *
     * The ledger is quite right that nothing is sellable — but it is this basket the stock
     * is not sellable *to anybody else*, and revalidating against the raw figure told the
     * customer the thing they were paying for was sold out and emptied their basket while
     * they were at the bank.
     */
    $everything = $this->stock->sellableFor($this->sku);

    $this->carts->add($this->customer, $this->sku, $everything);
    $session = $this->checkout->openCart($this->customer, []);

    expect($this->stock->sellableFor($this->sku))->toBe(0);

    $cart = $this->carts->forUser($this->customer);
    $issues = $this->carts->revalidate($cart);

    expect($issues)->toBe([])
        ->and($cart->fresh(['items'])?->items)->toHaveCount(1)
        ->and($session->fresh()?->status)->toBe(CheckoutStatus::Open);
});

it('takes a sold-out listing off the shelf without waiting for the seller', function (): void {
    /*
     * `product_skus.stock_quantity` is what the catalogue's list query reads. It used to
     * be written only by the seller's own stock endpoint, so buying the last unit left the
     * listing advertising stock until a seller happened to open the stock page — and the
     * next customer found out at checkout.
     */
    $everything = $this->stock->sellableFor($this->sku);

    $this->carts->add($this->customer, $this->sku, $everything);
    $session = $this->checkout->openCart($this->customer, []);

    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    expect($this->sku->fresh()?->stock_quantity)->toBe(0)
        ->and($this->sku->fresh()?->isAvailable())->toBeFalse()
        // Still a listing, just an empty one — which is what a basket revalidating its own
        // lines needs to be able to tell apart from a withdrawn offer.
        ->and($this->sku->fresh()?->isOffered())->toBeTrue();
});

it('credits a wallet exactly once for a package', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);

    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $wallet = app(CreditLedger::class)->walletFor($this->customer);

    // Paid credits and bonus credits are separate lots, because a refund gives back what
    // somebody paid for and should not claw back a gift.
    expect($wallet->balance)->toBe(120)
        ->and(CreditTransaction::query()->where('wallet_id', $wallet->getKey())->count())->toBe(2);
});

// --- duplicates: the gate ----------------------------------------------------

it('loads credits once however many times the provider says so', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);

    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    expect($intent->status)->toBe(PaymentStatus::RequiresAction);

    $body = webhookBody($intent, 'captured');

    // The same delivery four times: a provider that did not see our 200 in time.
    foreach (range(1, 4) as $ignored) {
        $outcome = $this->inbox->receive(FakePaymentGateway::NAME, signedHeaders($body), $body);
        processQueuedWebhook($outcome['event']);
    }

    $wallet = app(CreditLedger::class)->walletFor($this->customer);

    expect($wallet->balance)->toBe(120)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(CreditTransaction::query()->where('wallet_id', $wallet->getKey())->count())->toBe(2);
});

it('loads credits once even when two different events carry the same news', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    /*
     * Not a duplicate by any fingerprint: two distinct events, from two provider feeds,
     * both saying "captured". The inbox cannot help here — the state machine is what
     * stops the second one, because captured→captured is not a transition.
     */
    foreach (['evt_first', 'evt_second'] as $eventId) {
        $body = webhookBody($intent, 'captured', $eventId);
        $outcome = $this->inbox->receive(FakePaymentGateway::NAME, signedHeaders($body), $body);
        processQueuedWebhook($outcome['event']);
    }

    $wallet = app(CreditLedger::class)->walletFor($this->customer);

    expect(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and($wallet->balance)->toBe(120);
});

it('ignores news that arrives after the fact', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    expect($intent->status)->toBe(PaymentStatus::Captured);

    // A retried "failed" from before the capture, delivered late.
    $body = webhookBody($intent, 'failed', 'evt_late');
    $outcome = $this->inbox->receive(FakePaymentGateway::NAME, signedHeaders($body), $body);
    processQueuedWebhook($outcome['event']);

    /*
     * Dropped on purpose. The alternative is a record saying we were not paid while the
     * money sits in the account — and a customer whose credits vanish for no reason.
     */
    expect($intent->fresh()?->status)->toBe(PaymentStatus::Captured)
        ->and(app(CreditLedger::class)->walletFor($this->customer)?->balance)->toBe(120);
});

// --- replays and forgeries ---------------------------------------------------

it('stores an unsigned event and refuses to act on it', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    $body = webhookBody($intent, 'captured');

    $outcome = $this->inbox->receive(
        FakePaymentGateway::NAME,
        ['x-refconcept-signature' => 'not-the-signature', 'content-type' => 'application/json'],
        $body,
    );

    expect($outcome['verified'])->toBeFalse()
        /*
         * Stored rather than dropped. It is either a misconfigured secret — better seen
         * as a row than as a 401 in a log nobody reads — or somebody forging payment
         * confirmations, which is very much worth keeping.
         */
        ->and($outcome['event']?->status)->toBe('failed')
        ->and($intent->fresh()?->status)->toBe(PaymentStatus::RequiresAction)
        ->and(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(0);
});

it('refuses an event claiming more money than the payment is for', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    $body = webhookBody($intent, 'captured', 'evt_inflated', $intent->amount_minor * 10);
    $outcome = $this->inbox->receive(FakePaymentGateway::NAME, signedHeaders($body), $body);
    processQueuedWebhook($outcome['event']);

    expect($outcome['event']?->fresh()?->status)->toBe('failed')
        ->and($intent->fresh()?->status)->toBe(PaymentStatus::RequiresAction);
});

// --- timeouts ----------------------------------------------------------------

it('leaves a timed-out payment retryable rather than closing the checkout', function (): void {
    $this->carts->add($this->customer, $this->sku);
    $session = $this->checkout->openCart($this->customer, []);

    $failed = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_TIMEOUT);

    expect($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->failure_code)->toBe('gateway_timeout')
        /*
         * The session survives. A declined or timed-out card is overwhelmingly a card the
         * customer can simply try again with, and throwing away the price snapshot would
         * make them start over at whatever the prices are by then.
         */
        ->and($session->fresh()?->status)->toBe(CheckoutStatus::Failed);

    $second = $this->checkout->pay($session->fresh(), null, FakePaymentGateway::TOKEN_SUCCESS);

    expect($second->status)->toBe(PaymentStatus::Captured)
        ->and($second->getKey())->not->toBe($failed->getKey())
        // Both attempts are kept: the history a chargeback is argued from.
        ->and(PaymentIntent::query()->where('checkout_session_id', $session->getKey())->count())->toBe(2);
});

it('asks the provider when it does not know', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    // The fake answers from our own record, so the query is forced to see a capture that
    // happened elsewhere — which is exactly the situation after a browser closed mid-3DS.
    $intent->forceFill(['status' => PaymentStatus::Captured])->saveQuietly();

    $synced = $this->processor->synchronise($intent->fresh());

    expect($synced->status)->toBe(PaymentStatus::Captured)
        ->and(PaymentTransaction::query()
            ->where('payment_intent_id', $intent->getKey())
            ->where('type', 'query')
            ->count())->toBe(1);
});

// --- one payment at a time ---------------------------------------------------

it('refuses a second payment while one is at the bank', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);

    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    expect(fn () => $this->checkout->pay($session->fresh(), null, FakePaymentGateway::TOKEN_SUCCESS))
        ->toThrow(CheckoutRefused::class);
});

it('will not start a payment for a session already paid', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);

    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    expect(fn () => $this->checkout->pay($session->fresh(), null, FakePaymentGateway::TOKEN_SUCCESS))
        ->toThrow(CheckoutRefused::class);
});

// --- refunds -----------------------------------------------------------------

it('sends money back and will not send more than it took', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $partly = $this->processor->refund($intent, 10_000, 'Müşteri talebi');

    expect($partly->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($partly->refundableMinor())->toBe(39_900);

    expect(fn () => $this->processor->refund($partly, 999_999))
        ->toThrow(InvalidArgumentException::class);

    $fully = $this->processor->refund($partly, 39_900);

    expect($fully->status)->toBe(PaymentStatus::Refunded)
        ->and($fully->refundableMinor())->toBe(0);
});

it('treats a retried refund as the same refund', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $this->processor->refund($intent, 10_000, null, 'refund-key-1');
    $this->processor->refund($intent->fresh(), 10_000, null, 'refund-key-1');

    expect($intent->fresh()?->refunded_minor)->toBe(10_000)
        ->and(PaymentTransaction::query()
            ->where('payment_intent_id', $intent->getKey())
            ->where('type', 'refund')
            ->count())->toBe(1);
});

// --- the record ---------------------------------------------------------------

it('will not let the financial record be edited', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $intent = $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_SUCCESS);

    $row = PaymentTransaction::query()->where('payment_intent_id', $intent->getKey())->firstOrFail();

    /*
     * Enforced by the database, not by an Eloquent guard a raw query would walk past. A
     * ledger that can be edited is a ledger nobody can rely on in a dispute, and disputes
     * are the whole reason for having one.
     */
    expect(fn () => DB::table('payment_transactions')
        ->where('id', $row->getKey())
        ->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class);
});

// --- expiry -------------------------------------------------------------------

it('closes an abandoned checkout and gives the stock back', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);
    $session = $this->checkout->openCart($this->customer, []);

    expect($this->stock->sellableFor($this->sku))->toBe(1);

    $session->forceFill(['expires_at' => now()->subMinute()])->save();

    expect($this->checkout->expireOverdue())->toBe(1)
        ->and($session->fresh()?->status)->toBe(CheckoutStatus::Expired)
        // Back on the shelf: a hold nobody came back for is a sofa somebody else is being
        // told is sold out.
        ->and($this->stock->sellableFor($this->sku))->toBe(4);
});

it('leaves a checkout alone while its customer is at the bank', function (): void {
    $session = $this->checkout->openCredits($this->customer, $this->package);
    $this->checkout->pay($session, null, FakePaymentGateway::TOKEN_3DS);

    $session->fresh()?->forceFill(['expires_at' => now()->subMinute()])->save();

    /*
     * Coming back from a bank's 3DS page to "your payment expired" — while the bank
     * believes it succeeded — is worse than a session that outlives its advertised span.
     */
    expect($this->checkout->expireOverdue())->toBe(0)
        ->and($this->checkout->liveSession($this->customer, CheckoutPurpose::Credits))->not->toBeNull();
});

// --- helpers ------------------------------------------------------------------

/**
 * A provider event, in the shape the fake gateway speaks.
 */
function webhookBody(PaymentIntent $intent, string $status, ?string $eventId = null, ?int $amountMinor = null): string
{
    return (string) json_encode([
        'event_id' => $eventId ?? 'evt_'.$intent->getKey(),
        'type' => 'payment.'.$status,
        'payment_id' => $intent->external_id,
        'status' => $status,
        'amount_minor' => $amountMinor ?? $intent->amount_minor,
        'currency' => $intent->currency,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * @return array<string, string>
 */
function signedHeaders(string $body): array
{
    return [
        'x-refconcept-signature' => app(FakePaymentGateway::class)->sign($body),
        'content-type' => 'application/json',
    ];
}

/**
 * Runs the queued work the inbox would have dispatched.
 *
 * The queue is synchronous in tests, so the job has usually already run — but a
 * duplicate delivery returns the stored event without queueing anything, and calling
 * this on it must be a no-op rather than a second run. That is the property under test
 * in half this file, so it is exercised here rather than assumed.
 */
function processQueuedWebhook(?PaymentWebhookEvent $event): void
{
    if ($event === null) {
        return;
    }

    app(WebhookProcessor::class)->process($event->fresh() ?? $event);
}

<?php

declare(strict_types=1);

use App\Domains\Commerce\Services\CartService;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Payments\Gateways\FakePaymentGateway;
use App\Domains\Payments\Models\IdempotencyKey;
use App\Domains\Payments\Models\PaymentIntent;
use App\Domains\Payments\Models\PaymentWebhookEvent;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The endpoints, and the two things about them that are not ordinary CRUD.
 *
 * First, `/checkout` carries no id: it is always *your* live session, which is the same
 * ownership rule the cart routes use and the strongest form it can take — a forgotten
 * check cannot expose somebody else's basket when there is no way to name one.
 *
 * Second, `/checkout/pay` is the one route in the codebase where sending the same request
 * twice costs real money, so it honours an `Idempotency-Key`. The tests below are about
 * that header behaving the way a client on a bad connection needs it to.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->carts = app(CartService::class);
    $this->stock = app(InventoryLedger::class);

    [$this->seller] = makeApprovedSeller('Ödeme HTTP A.Ş.', 'odeme-http');

    $this->category = makeCategory('Sehpa', 'sehpa-odeme', 'living_room');

    $this->product = makeProduct($this->seller, $this->category, [
        'name' => 'Ödeme test sehpası',
        'description' => 'Uç nokta testleri için sehpa.',
        'price_minor' => 300_000,
        'stock_quantity' => 5,
    ]);

    $this->sku = $this->product->skus->first();
    $this->stock->adjust($this->stock->itemFor($this->sku), 5, MovementType::Receipt);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();

    UserAddress::query()->create([
        'user_id' => $this->customer->getKey(),
        'recipient_name' => 'Deniz Yılmaz',
        'city' => 'İstanbul',
        'address_line1' => 'Bağdat Caddesi 100',
        'is_default_shipping' => true,
        'is_default_billing' => true,
    ]);

    $this->package = CreditPackage::query()->create([
        'code' => 'http-paket',
        'name' => 'HTTP paketi',
        'credits' => 50,
        'price_minor' => 29_900,
        'currency' => 'TRY',
    ]);
});

it('opens a checkout and never caches it', function (): void {
    $this->carts->add($this->customer, $this->sku, 2);

    $response = $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout')
        ->assertCreated()
        // A checkout page holds an address, a basket and a total; a shared computer with a
        // back button must not show the last customer's.
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('data.totals.grand_total_minor'))->toBe(600_000)
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.shipping_address.city'))->toBe('İstanbul');
});

it('refuses to take money from an unverified account', function (): void {
    $unverified = User::factory()->create();
    $unverified->forceFill(['email_verified_at' => null])->save();

    /*
     * An unverified account is one somebody typed an address into. Letting it buy things
     * is how a marketplace becomes a card-testing service for stolen numbers.
     */
    $this->actingAs($unverified)
        ->postJson('/api/v1/checkout')
        ->assertForbidden();
});

it('replays the first answer when the same payment is sent twice', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => $this->package->getKey()])
        ->assertCreated();

    $body = ['purpose' => 'credits', 'payment_token' => FakePaymentGateway::TOKEN_SUCCESS];

    $first = $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-1')
        ->postJson('/api/v1/checkout/pay', $body)
        ->assertCreated();

    $second = $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-1')
        ->postJson('/api/v1/checkout/pay', $body)
        ->assertCreated()
        ->assertHeader('Idempotent-Replay', 'true');

    expect($second->json('data.payment.id'))->toBe($first->json('data.payment.id'))
        // One payment, one wallet load, however many times the button was pressed.
        ->and(PaymentIntent::query()->count())->toBe(1)
        ->and(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(50);
});

it('refuses a key that has been used for something else', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => $this->package->getKey()])
        ->assertCreated();

    $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-2')
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'credits', 'payment_token' => FakePaymentGateway::TOKEN_SUCCESS])
        ->assertCreated();

    /*
     * Not a retry — a mistake, or somebody probing. Answering it with the stored result
     * would be worse than either: the caller would believe a request it never made had
     * succeeded.
     */
    $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-2')
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'cart'])
        ->assertStatus(422);
});

it('does not keep a failed answer under a key', function (): void {
    // No session at all, so the request fails.
    $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-3')
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'cart'])
        ->assertNotFound();

    /*
     * Storing a failure would freeze a transient problem into a permanent one for that
     * key: the client retries, gets the same error forever, and fixing the server does
     * not help.
     */
    expect(IdempotencyKey::query()->where('key', 'client-key-3')->count())->toBe(0);

    $this->carts->add($this->customer, $this->sku);
    $this->actingAs($this->customer)->postJson('/api/v1/checkout')->assertCreated();

    $this->actingAs($this->customer)
        ->withHeader('Idempotency-Key', 'client-key-3')
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'cart', 'payment_token' => FakePaymentGateway::TOKEN_SUCCESS])
        ->assertCreated();
});

it('sends the customer to the bank and back', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => $this->package->getKey()])
        ->assertCreated();

    $started = $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'credits', 'payment_token' => FakePaymentGateway::TOKEN_3DS])
        ->assertCreated();

    $redirect = $started->json('data.payment.redirect_url');

    expect($redirect)->toContain('/challenge');

    $externalId = (string) PaymentIntent::query()->value('external_id');

    // The stand-in for a bank's page: plain, and clearly labelled as not being one.
    $this->get('/api/v1/payments/fake/'.$externalId.'/challenge')
        ->assertOk()
        ->assertSee('gerçek bir banka sayfası değildir', escape: false);

    $this->postJson('/api/v1/payments/fake/'.$externalId.'/complete', ['outcome' => 'captured'])
        ->assertOk();

    expect(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(50);
});

it('answers a duplicate webhook with 200 rather than an error', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => $this->package->getKey()])
        ->assertCreated();

    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'credits', 'payment_token' => FakePaymentGateway::TOKEN_3DS])
        ->assertCreated();

    $intent = PaymentIntent::query()->firstOrFail();

    $body = (string) json_encode([
        'event_id' => 'evt_http_1',
        'type' => 'payment.captured',
        'payment_id' => $intent->external_id,
        'status' => 'captured',
        'amount_minor' => $intent->amount_minor,
        'currency' => 'TRY',
    ], JSON_UNESCAPED_UNICODE);

    $signature = app(FakePaymentGateway::class)->sign($body);

    $first = $this->call(
        'POST',
        '/api/v1/payments/webhooks/fake',
        server: ['HTTP_X_REFCONCEPT_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    );

    $second = $this->call(
        'POST',
        '/api/v1/payments/webhooks/fake',
        server: ['HTTP_X_REFCONCEPT_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    );

    /*
     * A provider told that a duplicate failed will resend it forever. A retry storm on a
     * payment endpoint is a self-inflicted outage, so the honest answer to "we have this
     * already" is 200.
     */
    expect($first->getStatusCode())->toBe(200)
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->json('duplicate'))->toBeTrue()
        ->and(PaymentWebhookEvent::query()->count())->toBe(1)
        ->and(app(CreditLedger::class)->walletFor($this->customer)->balance)->toBe(50);
});

it('refuses a webhook that is not signed', function (): void {
    $body = (string) json_encode(['event_id' => 'evt_forged', 'status' => 'captured', 'payment_id' => 'fake_nothing']);

    $response = $this->call(
        'POST',
        '/api/v1/payments/webhooks/fake',
        server: ['HTTP_X_REFCONCEPT_SIGNATURE' => 'wrong', 'CONTENT_TYPE' => 'application/json'],
        content: $body,
    );

    expect($response->getStatusCode())->toBe(401)
        // Kept anyway: an unsigned event claiming a payment succeeded is either a
        // misconfiguration or an attack, and both deserve a row.
        ->and(PaymentWebhookEvent::query()->where('signature_verified', false)->count())->toBe(1);
});

it('does not confirm which providers exist to somebody probing', function (): void {
    $response = $this->call(
        'POST',
        '/api/v1/payments/webhooks/some-bank',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    expect($response->getStatusCode())->toBe(404);
});

it('will not tell one customer about another payment', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/credits', ['package_id' => $this->package->getKey()])
        ->assertCreated();

    $this->actingAs($this->customer)
        ->postJson('/api/v1/checkout/pay', ['purpose' => 'credits', 'payment_token' => FakePaymentGateway::TOKEN_3DS])
        ->assertCreated();

    $intent = PaymentIntent::query()->firstOrFail();

    $stranger = User::factory()->create();
    $stranger->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($stranger)
        ->getJson('/api/v1/payments/'.$intent->getKey())
        ->assertNotFound();

    $this->actingAs($this->customer)
        ->getJson('/api/v1/payments/'.$intent->getKey())
        ->assertOk();
});

it('gives the stock back when the customer backs out', function (): void {
    $this->carts->add($this->customer, $this->sku, 3);

    $this->actingAs($this->customer)->postJson('/api/v1/checkout')->assertCreated();

    expect($this->stock->sellableFor($this->sku))->toBe(2);

    $this->actingAs($this->customer)
        ->deleteJson('/api/v1/checkout', ['purpose' => 'cart'])
        ->assertOk();

    // Immediately, rather than left to expire: fifteen minutes of a sofa being unbuyable
    // for no reason is fifteen minutes of somebody else being told it is sold out.
    expect($this->stock->sellableFor($this->sku))->toBe(5);
});

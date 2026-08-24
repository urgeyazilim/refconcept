<?php

declare(strict_types=1);

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Services\InventoryLedger;
use App\Domains\Partners\Models\ApiCredential;
use App\Domains\Partners\Models\ApiRequestLog;
use App\Domains\Partners\Services\CredentialIssuer;
use App\Domains\Pricing\Models\PriceHistory;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The machine-facing half of the seller API.
 *
 * The properties that matter are all about what a leaked or mis-scoped credential can
 * do, not about the happy path: the secret is never recoverable, a warehouse
 * integration cannot change prices, and one seller's key cannot touch another's stock.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    $this->issuer = app(CredentialIssuer::class);

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');
    [$this->rivalSeller, $this->rivalUser] = makeApprovedSeller('Nova Yaşam', 'nova-yasam');

    $this->product = Product::factory()->forSeller($this->seller)->create();

    $this->sku = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-001',
        'list_price_minor' => 4_890_000,
        'stock_quantity' => 0,
    ]);

    app(InventoryLedger::class)->adjust(
        app(InventoryLedger::class)->itemFor($this->sku),
        10,
        MovementType::Receipt,
    );
});

/**
 * @param  array<int, string>  $scopes
 * @return array{0: ApiCredential, 1: array<string, string>}
 */
function partnerCredential(array $scopes = ['stock:read', 'stock:write']): array
{
    $issued = app(CredentialIssuer::class)->issue(
        organization: test()->seller->organization,
        name: 'Depo entegrasyonu',
        scopes: $scopes,
        actor: test()->sellerUser,
    );

    return [
        $issued['credential'],
        [
            'X-RefConcept-Key' => $issued['credential']->key_id,
            'X-RefConcept-Secret' => $issued['secret'],
            'Accept' => 'application/json',
        ],
    ];
}

// --- issuing --------------------------------------------------------------------

it('returns the secret exactly once and never again', function (): void {
    $response = $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/api-credentials', [
            'name' => 'ERP',
            'scopes' => ['stock:read', 'stock:write'],
        ])
        ->assertCreated();

    $secret = $response->json('data.secret');

    expect($secret)->toStartWith('rcs_');

    // Every later read shows only the hint. A credential a system can hand back on
    // demand is one an attacker with read access can also collect.
    $listed = $this->actingAs($this->sellerUser)
        ->getJson('/api/v1/seller/api-credentials')
        ->assertOk();

    expect(json_encode($listed->json()))->not->toContain($secret)
        ->and($listed->json('data.0.secret_hint'))->toBe('****'.substr($secret, -4));
});

it('never stores or serialises the secret itself', function (): void {
    [$credential] = partnerCredential();

    // Hashed at rest, and hidden from every response even if somebody adds one.
    expect($credential->secret_hash)->not->toContain('rcs_')
        ->and($credential->toArray())->not->toHaveKey('secret_hash');
});

it('refuses a credential with no scopes', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/api-credentials', ['name' => 'Boş', 'scopes' => []])
        ->assertStatus(422);
});

it('refuses an unknown scope', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/api-credentials', [
            'name' => 'Fazla yetkili',
            'scopes' => ['everything:write'],
        ])
        ->assertStatus(422);
});

// --- authenticating ----------------------------------------------------------------

it('accepts a valid key and secret', function (): void {
    [, $headers] = partnerCredential();

    $this->withHeaders($headers)
        ->getJson('/api/v1/partner/stock')
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'ATL-KNP-001')
        ->assertJsonPath('data.0.sellable', 10);
});

it('accepts the same credential as HTTP basic auth', function (): void {
    $issued = $this->issuer->issue(
        organization: $this->seller->organization,
        name: 'Basic',
        scopes: ['stock:read'],
        actor: $this->sellerUser,
    );

    // Both forms are what integrators' HTTP clients make easy; refusing one buys nothing.
    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode($issued['credential']->key_id.':'.$issued['secret']),
        'Accept' => 'application/json',
    ])
        ->getJson('/api/v1/partner/stock')
        ->assertOk();
});

it('refuses a wrong secret with the same answer as an unknown key', function (): void {
    [$credential] = partnerCredential();

    $wrongSecret = $this->withHeaders([
        'X-RefConcept-Key' => $credential->key_id,
        'X-RefConcept-Secret' => 'rcs_wrong',
        'Accept' => 'application/json',
    ])->getJson('/api/v1/partner/stock');

    $unknownKey = $this->withHeaders([
        'X-RefConcept-Key' => 'rck_nosuchkey',
        'X-RefConcept-Secret' => 'rcs_wrong',
        'Accept' => 'application/json',
    ])->getJson('/api/v1/partner/stock');

    // Identical: telling a caller the key exists but the secret is wrong is telling
    // them the key exists.
    expect($wrongSecret->status())->toBe(401)
        ->and($unknownKey->status())->toBe(401)
        ->and($wrongSecret->json('message'))->toBe($unknownKey->json('message'));
});

it('refuses a revoked credential', function (): void {
    [$credential, $headers] = partnerCredential();

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertOk();

    $this->actingAs($this->sellerUser)
        ->deleteJson("/api/v1/seller/api-credentials/{$credential->getKey()}", [
            'reason' => 'Entegrasyon kapatıldı.',
        ])
        ->assertOk();

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertStatus(401);
});

it('demands a reason before revoking', function (): void {
    [$credential] = partnerCredential();

    // An unexplained dead credential is a support ticket, and a CHECK constraint
    // refuses the row without one.
    $this->actingAs($this->sellerUser)
        ->deleteJson("/api/v1/seller/api-credentials/{$credential->getKey()}")
        ->assertStatus(422);
});

it('refuses a credential that has expired', function (): void {
    $issued = $this->issuer->issue(
        organization: $this->seller->organization,
        name: 'Kısa ömürlü',
        scopes: ['stock:read'],
        actor: $this->sellerUser,
        expiresInDays: 1,
    );

    $headers = [
        'X-RefConcept-Key' => $issued['credential']->key_id,
        'X-RefConcept-Secret' => $issued['secret'],
        'Accept' => 'application/json',
    ];

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertOk();

    $this->travel(2)->days();

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertStatus(401);
});

// --- scopes -------------------------------------------------------------------------

it('refuses a write with a read-only credential', function (): void {
    $issued = $this->issuer->issue(
        organization: $this->seller->organization,
        name: 'Salt okunur',
        scopes: ['stock:read'],
        actor: $this->sellerUser,
    );

    $this->withHeaders([
        'X-RefConcept-Key' => $issued['credential']->key_id,
        'X-RefConcept-Secret' => $issued['secret'],
        'Accept' => 'application/json',
    ])
        ->postJson('/api/v1/partner/stock', [
            'items' => [['sku' => 'ATL-KNP-001', 'quantity' => 3]],
        ])
        ->assertStatus(403);
});

it('does not let a stock credential change prices', function (): void {
    [, $headers] = partnerCredential(['stock:read', 'stock:write']);

    // The whole reason scopes exist: an ERP that pushes stock levels should not also
    // be able to reprice the catalogue.
    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/prices', [
            'items' => [['sku' => 'ATL-KNP-001', 'list_price_minor' => 1]],
        ])
        ->assertStatus(403);

    expect($this->sku->fresh()->list_price_minor->amountMinor)->toBe(4_890_000);
});

// --- writing ------------------------------------------------------------------------

it('sets stock from the seller own system as a count', function (): void {
    [, $headers] = partnerCredential();

    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/stock', [
            'items' => [['sku' => 'ATL-KNP-001', 'quantity' => 4]],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.ok', true)
        ->assertJsonPath('meta.accepted', 1);

    // A count, not a delta: a delta from an external system would double on retry.
    expect(app(InventoryLedger::class)->itemFor($this->sku->fresh())->on_hand)->toBe(4);
});

it('reports unknown SKUs per line instead of failing the whole request', function (): void {
    [, $headers] = partnerCredential();

    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/stock', [
            'items' => [
                ['sku' => 'ATL-KNP-001', 'quantity' => 7],
                ['sku' => 'BILINMEYEN', 'quantity' => 2],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('meta.accepted', 1)
        ->assertJsonPath('meta.rejected', 1)
        ->assertJsonPath('data.1.error', 'unknown_sku');

    // A nightly sync that refuses 4,000 updates because one product was discontinued
    // is a sync that gets switched off.
    expect(app(InventoryLedger::class)->itemFor($this->sku->fresh())->on_hand)->toBe(7);
});

it('refuses a count below what is already promised to customers', function (): void {
    [, $headers] = partnerCredential();

    $ledger = app(InventoryLedger::class);
    $ledger->reserve($ledger->itemFor($this->sku), 6, 'order', (string) Str::uuid7());

    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/stock', [
            'items' => [['sku' => 'ATL-KNP-001', 'quantity' => 2]],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.error', 'reserved_exceeds_count');

    // Somebody has to decide which order to cancel, and it is not this endpoint.
    expect($ledger->itemFor($this->sku->fresh())->on_hand)->toBe(10);
});

it('sets prices in minor units and records the source as the API', function (): void {
    [, $headers] = partnerCredential(['prices:write']);

    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/prices', [
            'items' => [['sku' => 'ATL-KNP-001', 'list_price_minor' => 5_190_000]],
        ])
        ->assertOk()
        ->assertJsonPath('meta.accepted', 1);

    expect($this->sku->fresh()->list_price_minor->amountMinor)->toBe(5_190_000)
        ->and(PriceHistory::query()->where('source', 'api')->exists())->toBeTrue();
});

// --- tenancy ---------------------------------------------------------------------------

it('never lets one seller credential see another seller stock', function (): void {
    [, $headers] = partnerCredential();

    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    ProductSku::query()->create([
        'product_id' => $rivalProduct->getKey(),
        'seller_id' => $this->rivalSeller->getKey(),
        'sku' => 'NOVA-001',
        'list_price_minor' => 1_000_000,
        'stock_quantity' => 5,
    ]);

    $response = $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertOk();

    expect(collect($response->json('data'))->pluck('sku')->all())->toBe(['ATL-KNP-001']);
});

it('treats another seller SKU code as unknown rather than as an error', function (): void {
    [, $headers] = partnerCredential();

    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    $rivalSku = ProductSku::query()->create([
        'product_id' => $rivalProduct->getKey(),
        'seller_id' => $this->rivalSeller->getKey(),
        'sku' => 'NOVA-002',
        'list_price_minor' => 1_000_000,
        'stock_quantity' => 5,
    ]);

    $this->withHeaders($headers)
        ->postJson('/api/v1/partner/stock', [
            'items' => [['sku' => 'NOVA-002', 'quantity' => 999]],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.error', 'unknown_sku');

    expect($rivalSku->fresh()->stock_quantity)->toBe(5);
});

it('never lets one seller read another seller credential usage', function (): void {
    [$credential] = partnerCredential();

    $this->actingAs($this->rivalUser)
        ->getJson("/api/v1/seller/api-credentials/{$credential->getKey()}/usage")
        ->assertNotFound();
});

// --- limits and logging ------------------------------------------------------------------

it('rate-limits per credential', function (): void {
    $issued = $this->issuer->issue(
        organization: $this->seller->organization,
        name: 'Yoğun',
        scopes: ['stock:read'],
        actor: $this->sellerUser,
        rateLimitPerMinute: 10,
    );

    $headers = [
        'X-RefConcept-Key' => $issued['credential']->key_id,
        'X-RefConcept-Secret' => $issued['secret'],
        'Accept' => 'application/json',
    ];

    RateLimiter::clear('partner:'.$issued['credential']->getKey());

    foreach (range(1, 10) as $ignored) {
        $this->withHeaders($headers)->getJson('/api/v1/partner/stock')->assertOk();
    }

    $this->withHeaders($headers)
        ->getJson('/api/v1/partner/stock')
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('logs the path but never the query string', function (): void {
    [$credential, $headers] = partnerCredential();

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock?sku[]=SECRET-CODE');

    $log = ApiRequestLog::query()->where('credential_id', $credential->getKey())->latest('created_at')->firstOrFail();

    // Support staff read this table. A query string can carry a whole SKU list.
    expect($log->path)->toBe('api/v1/partner/stock')
        ->and($log->status)->toBe(200);
});

it('records when a credential was last used', function (): void {
    [$credential, $headers] = partnerCredential();

    expect($credential->last_used_at)->toBeNull();

    $this->withHeaders($headers)->getJson('/api/v1/partner/stock');

    expect($credential->fresh()->last_used_at)->not->toBeNull();
});

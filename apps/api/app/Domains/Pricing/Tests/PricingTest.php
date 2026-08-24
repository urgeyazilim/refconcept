<?php

declare(strict_types=1);

use App\Domains\Pricing\Models\PriceHistory;
use App\Domains\Pricing\Models\PriceList;
use App\Domains\Pricing\Services\PriceBook;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Support\ValueObjects\Money;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prices, campaigns, and the record of how a number got that way.
 *
 * The two things worth protecting: a campaign must never overwrite the everyday
 * price, and every change must leave a row nobody can edit. Both are what make the
 * question "why does this cost 3.900 today" answerable at all.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    $this->prices = app(PriceBook::class);

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');
    [$this->rivalSeller, $this->rivalUser] = makeApprovedSeller('Nova Yaşam', 'nova-yasam');

    $this->product = Product::factory()->forSeller($this->seller)->create();

    $this->sku = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-001',
        'list_price_minor' => 4_890_000,
        'stock_quantity' => 5,
    ]);
});

// --- the SKU's own price ---------------------------------------------------------

it('records both sides of a price change', function (): void {
    $this->prices->setPrice($this->sku, Money::of(5_490_000), null, $this->sellerUser);

    $entry = PriceHistory::query()->where('field', 'list_price')->firstOrFail();

    expect($this->sku->fresh()->list_price_minor->amountMinor)->toBe(5_490_000)
        ->and($entry->old_value_minor)->toBe(4_890_000)
        ->and($entry->new_value_minor)->toBe(5_490_000)
        ->and($entry->source)->toBe('manual')
        ->and($entry->changed_by)->toBe($this->sellerUser->getKey());
});

it('writes nothing when the price did not actually move', function (): void {
    $this->prices->setPrice($this->sku, Money::of(4_890_000));

    // An unchanged value in the history is noise that makes a real change harder to
    // find, which defeats the point of keeping one.
    expect(PriceHistory::query()->count())->toBe(0);
});

it('reports the change in basis points', function (): void {
    $this->prices->setPrice($this->sku, Money::of(4_401_000));

    $entry = PriceHistory::query()->where('field', 'list_price')->firstOrFail();

    // 4.890.000 → 4.401.000 is a 10% drop.
    expect($entry->changeBps())->toBe(-1000);
});

it('refuses a sale price above the list price', function (): void {
    expect(fn () => $this->prices->setPrice($this->sku, Money::of(1_000_000), Money::of(1_500_000)))
        ->toThrow(InvalidArgumentException::class);
});

it('never lets a history row be edited or deleted', function (): void {
    $this->prices->setPrice($this->sku, Money::of(5_000_000));

    $entry = PriceHistory::query()->firstOrFail();

    // A price is the most disputed number in a marketplace. A history somebody with
    // database access can quietly edit answers nothing.
    expect(fn () => DB::table('price_history')->where('id', $entry->getKey())->update(['new_value_minor' => 1]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('price_history')->where('id', $entry->getKey())->delete())
        ->toThrow(QueryException::class);
});

// --- campaigns -------------------------------------------------------------------

it('creates the default list on first use', function (): void {
    $list = $this->prices->defaultListFor($this->seller->getKey());

    expect($list->is_default)->toBeTrue()
        ->and($list->code)->toBe('DEFAULT')
        // Asking twice must not produce two, and a partial unique index guarantees it.
        ->and($this->prices->defaultListFor($this->seller->getKey())->getKey())->toBe($list->getKey());
});

it('refuses a second default list for one seller', function (): void {
    $this->prices->defaultListFor($this->seller->getKey());

    expect(fn () => PriceList::query()->create([
        'seller_id' => $this->seller->getKey(),
        'code' => 'OTHER',
        'name' => 'İkinci varsayılan',
        'is_default' => true,
    ]))->toThrow(QueryException::class);
});

it('lets a campaign price win without overwriting the everyday price', function (): void {
    $campaign = PriceList::query()->create([
        'seller_id' => $this->seller->getKey(),
        'code' => 'BAHAR',
        'name' => 'Bahar kampanyası',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addWeek(),
    ]);

    $this->prices->setListPrice($campaign, $this->sku, Money::of(4_890_000), Money::of(3_900_000));

    $resolved = $this->prices->resolve($this->sku->fresh());

    expect($resolved['effective']->amountMinor)->toBe(3_900_000)
        ->and($resolved['source'])->toBe('campaign')
        // The whole point: yesterday's price is still there, untouched.
        ->and($this->sku->fresh()->list_price_minor->amountMinor)->toBe(4_890_000);
});

it('falls back to the SKU price when a campaign has ended', function (): void {
    $campaign = PriceList::query()->create([
        'seller_id' => $this->seller->getKey(),
        'code' => 'KIS',
        'name' => 'Kış kampanyası',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDay(),
    ]);

    $this->prices->setListPrice($campaign, $this->sku, Money::of(4_890_000), Money::of(3_500_000));

    expect($this->prices->resolve($this->sku->fresh())['effective']->amountMinor)->toBe(3_500_000);

    $this->travel(2)->days();

    // Ending the campaign restores the old prices because nothing overwrote them —
    // there is no "put it back" step for anybody to forget.
    $resolved = $this->prices->resolve($this->sku->fresh());

    expect($resolved['effective']->amountMinor)->toBe(4_890_000)
        ->and($resolved['source'])->toBe('sku');
});

it('ignores a campaign that has not started yet', function (): void {
    $campaign = PriceList::query()->create([
        'seller_id' => $this->seller->getKey(),
        'code' => 'YAZ',
        'name' => 'Yaz kampanyası',
        'starts_at' => now()->addWeek(),
    ]);

    $this->prices->setListPrice($campaign, $this->sku, Money::of(4_890_000), Money::of(2_900_000));

    expect($this->prices->resolve($this->sku->fresh())['effective']->amountMinor)->toBe(4_890_000);
});

it('refuses to price another seller offer through a price list', function (): void {
    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    $rivalSku = ProductSku::query()->create([
        'product_id' => $rivalProduct->getKey(),
        'seller_id' => $this->rivalSeller->getKey(),
        'sku' => 'NOVA-001',
        'list_price_minor' => 1_000_000,
        'stock_quantity' => 1,
    ]);

    $list = $this->prices->defaultListFor($this->seller->getKey());

    // A tenancy hole rather than a validation slip, so it is refused in the service as
    // well as in the policy above it.
    expect(fn () => $this->prices->setListPrice($list, $rivalSku, Money::of(1)))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a list item whose sale price is above its list price', function (): void {
    $list = $this->prices->defaultListFor($this->seller->getKey());

    expect(fn () => DB::table('price_list_items')->insert([
        'id' => (string) Str::uuid7(),
        'price_list_id' => $list->getKey(),
        'sku_id' => $this->sku->getKey(),
        'list_price_minor' => 100,
        'sale_price_minor' => 200,
        'currency' => 'TRY',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --- the endpoints ----------------------------------------------------------------

it('changes many prices in one request', function (): void {
    $second = ProductSku::query()->create([
        'product_id' => $this->product->getKey(),
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-KNP-002',
        'list_price_minor' => 1_000_000,
        'stock_quantity' => 3,
    ]);

    $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/prices/bulk', [
            'prices' => [
                ['sku_id' => $this->sku->getKey(), 'list_price_minor' => 5_100_000],
                ['sku_id' => $second->getKey(), 'list_price_minor' => 1_250_000, 'sale_price_minor' => 999_000],
            ],
        ])
        ->assertOk();

    expect($this->sku->fresh()->list_price_minor->amountMinor)->toBe(5_100_000)
        ->and($second->fresh()->sale_price_minor->amountMinor)->toBe(999_000)
        ->and(PriceHistory::query()->count())->toBe(3);
});

it('refuses a bulk payload containing another seller offer, wholesale', function (): void {
    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    $rivalSku = ProductSku::query()->create([
        'product_id' => $rivalProduct->getKey(),
        'seller_id' => $this->rivalSeller->getKey(),
        'sku' => 'NOVA-002',
        'list_price_minor' => 1_000_000,
        'stock_quantity' => 1,
    ]);

    $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/prices/bulk', [
            'prices' => [
                ['sku_id' => $this->sku->getKey(), 'list_price_minor' => 1],
                ['sku_id' => $rivalSku->getKey(), 'list_price_minor' => 1],
            ],
        ])
        ->assertStatus(422);

    // Not half-applied: a payload containing somebody else's SKU is either an attack
    // or a serious client bug, and neither is something to half-honour.
    expect($this->sku->fresh()->list_price_minor->amountMinor)->toBe(4_890_000)
        ->and($rivalSku->fresh()->list_price_minor->amountMinor)->toBe(1_000_000);
});

it('shows a seller the history of one offer and nobody else the same', function (): void {
    $this->prices->setPrice($this->sku, Money::of(5_000_000), null, $this->sellerUser);

    $this->actingAs($this->sellerUser)
        ->getJson("/api/v1/seller/prices/{$this->sku->getKey()}/history")
        ->assertOk()
        ->assertJsonPath('data.0.new_price.amount_minor', 5_000_000);

    $this->actingAs($this->rivalUser)
        ->getJson("/api/v1/seller/prices/{$this->sku->getKey()}/history")
        ->assertNotFound();
});

<?php

declare(strict_types=1);

use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\PlatformSettings;
use App\Domains\Ai\Services\ProviderCostInLira;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The platform reports in lira, everywhere, without exception.
 *
 * This is not a formatting preference. A screen that shows a dollar figure with a lira sign
 * is wrong by the whole exchange rate and wrong *silently* — nothing else in the system
 * disagrees with it, so nobody finds out until a total is compared against a bank statement.
 *
 * AI providers are the one thing that does not cooperate: Google publishes dollars per
 * million tokens. So the conversion happens once, when the usage row is written, and what is
 * stored is lira.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformSettingsSeeder::class);

    $this->cost = app(ProviderCostInLira::class);
});

it('supports exactly one currency', function (): void {
    // Stated in one place. A second list is how a currency nobody supports ends up
    // accepted by a form.
    expect(config('refconcept.money.supported_currencies'))->toBe(['TRY'])
        ->and(config('refconcept.money.default_currency'))->toBe('TRY');
});

it('refuses a product price in any other currency', function (): void {
    [$seller, $owner] = makeApprovedSeller('Kur Mobilya', 'kur-mobilya');
    $owner->forceFill(['email_verified_at' => now()])->save();

    $product = makeProduct($seller, makeCategory('Koltuk', 'koltuk-kur', 'living_room'), [
        'name' => 'Kur koltuğu',
        'description' => 'Kur testleri.',
        'price_minor' => 100_000,
        'stock_quantity' => 2,
    ]);

    $this->actingAs($owner)
        ->postJson('/api/v1/seller/products/'.$product->getKey().'/skus', [
            'sku' => 'KUR-USD-1',
            'list_price_minor' => 100_000,
            'currency' => 'USD',
        ])
        ->assertStatus(422);
});

it('converts a dollar-quoted provider cost into lira', function (): void {
    /*
     * The number matters. Two million micros is two dollars; at a rate of 34 that is
     * sixty-eight lira, and a screen that showed "₺2" for it would be wrong by a factor of
     * thirty-four.
     */
    config()->set('refconcept.fx.usd_try', 34.0);

    expect($this->cost->convert(2_000_000, 'USD'))->toBe(68_000_000)
        ->and($this->cost->currency())->toBe('TRY');
});

it('leaves a cost that is already lira alone', function (): void {
    expect($this->cost->convert(2_000_000, 'TRY'))->toBe(2_000_000)
        ->and($this->cost->convert(2_000_000, null))->toBe(2_000_000);
});

it('lets an operator update the rate without a deploy', function (): void {
    config()->set('refconcept.fx.usd_try', 34.0);

    SystemSetting::query()->where('key', 'finance.usd_try_rate')->update(['value' => '40']);
    app(PlatformSettings::class)->forget('finance.usd_try_rate');

    // The operator's value wins, so a rate that has drifted is fixed from the settings
    // screen rather than from a release.
    expect(app(ProviderCostInLira::class)->convert(1_000_000, 'USD'))->toBe(40_000_000);
});

it('never zeroes a cost when the rate is unusable', function (): void {
    config()->set('refconcept.fx.usd_try', 0.0);

    SystemSetting::query()->where('key', 'finance.usd_try_rate')->update(['value' => '']);
    app(PlatformSettings::class)->forget('finance.usd_try_rate');

    /*
     * A spend report reading zero looks like a quiet month rather than like a broken
     * conversion, and nobody investigates a quiet month. Wrong by a factor is recoverable;
     * wrong in a way that hides itself is not.
     */
    expect(app(ProviderCostInLira::class)->convert(2_000_000, 'USD'))->toBe(2_000_000);
});

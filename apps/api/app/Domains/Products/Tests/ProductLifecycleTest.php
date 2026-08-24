<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Models\Product;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\Seller;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The Phase 3 gate: seller product → admin approve → public product.
 *
 * Everything here asks the same underlying question in different ways — can an
 * unreviewed listing reach a customer? The answer has to be no through every route
 * that returns products, which is why the public endpoint is exercised as much as the
 * moderation one.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    $this->category = Category::query()->where('slug', 'kanepe')->firstOrFail();

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');
    [$this->rivalSeller, $this->rivalUser] = makeApprovedSeller('Nova Yaşam', 'nova-yasam');

    $this->operator = User::factory()->create();
    grantPlatformRole($this->operator, SystemRole::Operator);
});

// --- creation ---------------------------------------------------------------

it('lets a seller create a draft product', function (): void {
    $response = $this->actingAs($this->sellerUser)
        ->postJson('/api/v1/seller/products', [
            'name' => 'Modüler Kanepe',
            'description' => 'Bouclé kumaş, modüler oturma grubu.',
            'primary_category_id' => $this->category->getKey(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.moderation_status', ModerationStatus::Draft->value)
        ->assertJsonPath('data.status', ProductStatus::Draft->value);

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->first()->organization_id)->toBe($this->seller->organization_id);
});

it('refuses to let a plain customer create a product', function (): void {
    $customer = User::factory()->create();

    $this->actingAs($customer)
        ->postJson('/api/v1/seller/products', [
            'name' => 'Sahte Ürün',
            'primary_category_id' => $this->category->getKey(),
        ])
        ->assertForbidden();
});

it('generates a unique slug even for identical names', function (): void {
    foreach (range(1, 2) as $ignored) {
        $this->actingAs($this->sellerUser)->postJson('/api/v1/seller/products', [
            'name' => 'Aynı İsim',
            'primary_category_id' => $this->category->getKey(),
        ])->assertCreated();
    }

    expect(Product::query()->pluck('slug')->unique())->toHaveCount(2);
});

// --- pricing ----------------------------------------------------------------

it('stores prices as exact minor units', function (): void {
    $product = Product::factory()->forSeller($this->seller)->create();

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/skus", [
            'sku' => 'KANEPE-01',
            'list_price_minor' => 4_890_000,
            'stock_quantity' => 5,
        ])
        ->assertCreated();

    $sku = $product->fresh()->skus->first();

    expect($sku->list_price_minor->amountMinor)->toBe(4_890_000)
        ->and($sku->list_price_minor->currency)->toBe('TRY')
        ->and($sku->list_price_minor->format())->toBe('48.900,00 ₺');
});

it('refuses a sale price above the list price', function (): void {
    $product = Product::factory()->forSeller($this->seller)->create();

    // A negative discount reads as a storefront bug rather than a seller typo.
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/skus", [
            'sku' => 'KANEPE-02',
            'list_price_minor' => 1_000_000,
            'sale_price_minor' => 1_500_000,
            'stock_quantity' => 5,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sale_price_minor');
});

it('computes tax from basis points without rounding drift', function (): void {
    $product = Product::factory()->forSeller($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/skus", [
        'sku' => 'KANEPE-03',
        'list_price_minor' => 4_890_000,
        'tax_rate_bps' => 2000,
        'stock_quantity' => 1,
    ])->assertCreated();

    $sku = $product->fresh()->skus->first();

    expect($sku->taxAmount()->amountMinor)->toBe(978_000);
});

it('reports the discount in basis points', function (): void {
    $product = Product::factory()->forSeller($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/skus", [
        'sku' => 'KANEPE-04',
        'list_price_minor' => 1_000_000,
        'sale_price_minor' => 750_000,
        'stock_quantity' => 1,
    ])->assertCreated();

    $sku = $product->fresh()->skus->first();

    expect($sku->discountBps())->toBe(2500)
        ->and($sku->effectivePrice()->amountMinor)->toBe(750_000);
});

// --- submission ---------------------------------------------------------------

it('refuses to submit an incomplete listing and names what is missing', function (): void {
    $product = Product::factory()->forSeller($this->seller)->create(['description' => null]);

    $response = $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/submit")
        ->assertStatus(422);

    $message = $response->json('errors.moderation_status.0');

    expect($message)->toContain('görseli')
        ->and($message)->toContain('SKU');

    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::Draft);
});

it('submits a complete listing', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/submit")
        ->assertOk()
        ->assertJsonPath('data.moderation_status', ModerationStatus::PendingReview->value);
});

it('locks the listing while it waits for review', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    // Editing what a reviewer is about to read would mean approving something nobody saw.
    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", ['name' => 'Değiştirilmiş Ad'])
        ->assertForbidden();
});

// --- the gate: moderation to public visibility --------------------------------

it('keeps an unapproved listing out of the public catalogue', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    $response = $this->getJson('/api/v1/catalog/products')->assertOk();

    expect($response->json('data'))->toHaveCount(0);

    $this->getJson("/api/v1/catalog/products/{$product->slug}")->assertNotFound();
});

it('publishes an approved listing to the public catalogue', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [
            'reason' => 'Görseller ve ölçüler doğrulandı.',
        ])
        ->assertOk();

    // Approval publishes: submitting for review is the request to sell, so the seller
    // does not have to come back and flip a second switch they were never shown.
    expect($product->fresh()->status)->toBe(ProductStatus::Active);

    $response = $this->getJson('/api/v1/catalog/products')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($product->getKey());

    $this->getJson("/api/v1/catalog/products/{$product->slug}")
        ->assertOk()
        ->assertJsonPath('data.from_price.amount_minor', 4_890_000);
});

it('demands a reason for every moderation decision', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->pendingReview()->create();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::PendingReview);
});

it('records which fields a rejection flagged', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->pendingReview()->create();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/reject", [
            'reason' => 'Görsel çözünürlüğü yetersiz ve ölçüler eksik.',
            'flagged_fields' => ['media', 'dimensions'],
        ])
        ->assertOk();

    $decision = $product->fresh()->moderationDecisions->first();

    // Without the flagged fields the seller resubmits the same problem.
    expect($decision->decision)->toBe('rejected')
        ->and($decision->flagged_fields)->toBe(['media', 'dimensions']);
});

it('lets a seller fix and resubmit a rejected listing', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->pendingReview()->create();

    $this->actingAs($this->operator)->postJson("/api/v1/admin/products/{$product->getKey()}/reject", [
        'reason' => 'Açıklama yetersiz, lütfen genişletin.',
    ])->assertOk();

    expect($product->fresh()->isEditable())->toBeTrue();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", [
            'description' => 'Genişletilmiş açıklama: bouclé kumaş, modüler yapı, çıkarılabilir kılıf.',
        ])
        ->assertOk();

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/submit")
        ->assertOk()
        ->assertJsonPath('data.moderation_status', ModerationStatus::PendingReview->value);
});

it('takes a recalled listing off sale immediately', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->pendingReview()->create();

    $this->actingAs($this->operator)->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [
        'reason' => 'İlk incelemede uygun bulundu.',
    ])->assertOk();

    $product->fresh()->forceFill(['status' => ProductStatus::Active])->save();

    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(1);

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/recall", [
            'reason' => 'Tüketici şikâyeti üzerine yeniden incelemeye alındı.',
        ])
        ->assertOk();

    // A listing under suspicion must not stay on sale while it is looked at.
    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(0)
        ->and($product->fresh()->published_at)->toBeNull();
});

it('hides a listing the seller paused, without another review', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(1);

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}/status", ['status' => 'archived'])
        ->assertOk();

    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(0)
        // Moderation is untouched: resuming must not need another approval.
        ->and($product->fresh()->moderation_status)->toBe(ModerationStatus::Approved);
});

it('hides an approved listing whose seller was suspended', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(1);

    $this->seller->forceFill(['status' => SellerStatus::Suspended])->save();

    // Suspension has to reach the storefront, or a suspended seller keeps selling.
    expect($this->getJson('/api/v1/catalog/products')->json('data'))->toHaveCount(0);
});

// --- tenant isolation ---------------------------------------------------------

it('shows a seller only their own listings', function (): void {
    Product::factory()->forSeller($this->seller)->create(['name' => 'Bizim Ürün']);
    Product::factory()->forSeller($this->rivalSeller)->create(['name' => 'Rakip Ürün']);

    $response = $this->actingAs($this->sellerUser)->getJson('/api/v1/seller/products')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Bizim Ürün');
});

it('refuses to let one seller edit another seller listing', function (): void {
    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$rivalProduct->getKey()}", ['name' => 'Ele Geçirildi'])
        ->assertForbidden();

    $this->actingAs($this->sellerUser)
        ->deleteJson("/api/v1/seller/products/{$rivalProduct->getKey()}")
        ->assertForbidden();

    expect($rivalProduct->fresh()->name)->not->toBe('Ele Geçirildi');
});

it('refuses to let a seller attach a SKU to another seller listing', function (): void {
    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$rivalProduct->getKey()}/skus", [
            'sku' => 'KACAK-01',
            'list_price_minor' => 100_000,
            'stock_quantity' => 1,
        ])
        ->assertForbidden();
});

it('refuses to let a seller moderate anything, including their own listing', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->pendingReview()->create();

    // Self-approval would make review theatre.
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [
            'reason' => 'Kendi ürünümü onaylıyorum.',
        ])
        ->assertForbidden();

    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::PendingReview);
});

it('lets two sellers use the same SKU code', function (): void {
    $ourProduct = Product::factory()->forSeller($this->seller)->create();
    $rivalProduct = Product::factory()->forSeller($this->rivalSeller)->create();

    // Uniqueness is per seller, not global: "SOFA-01" is a common code.
    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$ourProduct->getKey()}/skus", [
        'sku' => 'SOFA-01',
        'list_price_minor' => 100_000,
        'stock_quantity' => 1,
    ])->assertCreated();

    $this->actingAs($this->rivalUser)->postJson("/api/v1/seller/products/{$rivalProduct->getKey()}/skus", [
        'sku' => 'SOFA-01',
        'list_price_minor' => 120_000,
        'stock_quantity' => 1,
    ])->assertCreated();
});

// --- public catalogue behaviour ------------------------------------------------

it('filters the catalogue by category branch', function (): void {
    $sofa = Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create([
        'primary_category_id' => Category::query()->where('slug', 'kanepe')->value('id'),
    ]);

    Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create([
        'primary_category_id' => Category::query()->where('slug', 'yatak')->value('id'),
    ]);

    // "oturma-grubu" is a branch; its children must be included.
    $response = $this->getJson('/api/v1/catalog/products?category=oturma-grubu')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($sofa->getKey());
});

it('returns nothing rather than everything for an unknown category', function (): void {
    Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    // Silently ignoring the filter would show the whole catalogue and look broken.
    expect($this->getJson('/api/v1/catalog/products?category=boyle-bir-sey-yok')->json('data'))
        ->toHaveCount(0);
});

it('filters by budget using the effective price', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    $sku = $product->skus->first();
    $sku->forceFill(['sale_price_minor' => 2_000_000])->save();

    // The list price is 4.890.000; the sale price is what the customer pays, so a
    // 25.000 ₺ budget must include this product.
    expect($this->getJson('/api/v1/catalog/products?max_price_minor=2500000')->json('data'))
        ->toHaveCount(1);

    expect($this->getJson('/api/v1/catalog/products?max_price_minor=1500000')->json('data'))
        ->toHaveCount(0);
});

it('serves the public catalogue without authentication', function (): void {
    Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    $this->getJson('/api/v1/catalog/products')->assertOk();
    $this->getJson('/api/v1/catalog/categories')->assertOk();
    $this->getJson('/api/v1/catalog/vocabulary')->assertOk();
});

it('returns prices as exact integers, not decimal strings', function (): void {
    Product::factory()->forSeller($this->seller)->complete($this->seller)->published()->create();

    $response = $this->getJson('/api/v1/catalog/products')->assertOk();

    // A client that receives "48900.00" will parse it into a float sooner or later.
    expect($response->json('data.0.skus.0.list_price.amount_minor'))->toBe(4_890_000)
        ->and($response->json('data.0.skus.0.list_price.currency'))->toBe('TRY');
});

// --- taxonomy the seller form is built from -------------------------------------

it('serves the attributes a category demands, marking which are required', function (): void {
    $response = $this->getJson('/api/v1/catalog/categories/kanepe/attributes')->assertOk();

    $byCode = collect($response->json('data'))->keyBy('code');

    // The form and ProductCompleteness read the same pivot flag, so a required field
    // in one can never be optional in the other.
    expect($byCode->get('color')['is_required'])->toBeTrue()
        ->and($byCode->get('size')['is_required'])->toBeFalse()
        ->and($byCode->get('color')['values'])->not->toBeEmpty();
});

it('does not expose the attributes of an inactive category', function (): void {
    Category::query()->where('slug', 'kanepe')->update(['is_active' => false]);

    $this->getJson('/api/v1/catalog/categories/kanepe/attributes')->assertNotFound();
});

it('serves brands without authentication', function (): void {
    $this->getJson('/api/v1/catalog/brands')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'slug']]]);
});

// --- publication side effects ---------------------------------------------------

it('activates draft offers on approval so an approved listing is actually buyable', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    // A listing built through the API starts with draft offers; the factory's are
    // active, so the state a real seller arrives in is set up explicitly here.
    $product->skus()->update(['status' => SkuStatus::Draft->value]);
    $product->fresh()->forceFill(['status' => ProductStatus::Draft])->save();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [
            'reason' => 'Görseller ve ölçüler doğrulandı.',
        ])
        ->assertOk();

    expect($product->fresh()->status)->toBe(ProductStatus::Active)
        ->and($product->skus()->pluck('status')->all())->each->toBe(SkuStatus::Active);

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(1, 'data');
});

it('leaves an offer the seller paused alone when the listing is approved', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $product->skus()->update(['status' => SkuStatus::Paused->value]);

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", ['reason' => 'İnceleme tamamlandı.'])
        ->assertOk();

    // Approval is a moderation decision, not a licence to undo the seller's own.
    expect($product->skus()->pluck('status')->all())->each->toBe(SkuStatus::Paused);

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data');
});

it('puts a paused listing and its offers back on sale together', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", ['reason' => 'İnceleme tamamlandı.'])
        ->assertOk();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}/status", ['status' => 'archived'])
        ->assertOk();

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}/status", ['status' => 'active'])
        ->assertOk();

    // Resuming has to restore the offers as well: a listing back on sale with every
    // offer still archived is visible, unbuyable and impossible for the seller to
    // diagnose from their own screen.
    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(1, 'data');
});

it('lists a seller listing with offers without lazy loading anything', function (): void {
    // Lazy loading is disabled outside production, so a missing eager load is a 500
    // rather than an N+1. The seller list is the screen that hits it: the "from" price
    // asks every offer whether its seller may trade.
    Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)
        ->getJson('/api/v1/seller/products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.from_price.amount_minor', 4_890_000);
});

// --- editing a published listing -------------------------------------------------

/** Approves a complete listing through the real endpoints and returns it published. */
function publishListing(Product $product, User $seller, User $operator): Product
{
    test()->actingAs($seller)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    test()->actingAs($operator)
        ->postJson("/api/v1/admin/products/{$product->getKey()}/approve", [
            'reason' => 'Görseller ve ölçüler doğrulandı.',
        ])
        ->assertOk();

    return $product->fresh();
}

it('lets a seller edit a published listing', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    publishListing($product, $this->sellerUser, $this->operator);

    // A marketplace where a live listing can never have a typo fixed is unusable, so
    // approved does not mean frozen.
    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", ['name' => 'Düzeltilmiş Ad'])
        ->assertOk();

    expect($product->fresh()->name)->toBe('Düzeltilmiş Ad');
});

it('sends an edited published listing back to the review queue', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    publishListing($product, $this->sellerUser, $this->operator);

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", [
            'description' => 'Tamamen farklı bir açıklama.',
        ])
        ->assertOk();

    $fresh = $product->fresh();

    // The whole point of the gate: what a customer sees is always something a reviewer
    // looked at, so the edit leaves the catalogue until it is approved again.
    expect($fresh->moderation_status)->toBe(ModerationStatus::PendingReview)
        ->and($fresh->published_at)->toBeNull();

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data');
});

it('sends a published listing back to review when its price changes', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    publishListing($product, $this->sellerUser, $this->operator);

    $sku = $product->skus()->firstOrFail();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}/skus/{$sku->getKey()}", [
            'sku' => $sku->sku,
            'list_price_minor' => 9_990_000,
        ])
        ->assertOk();

    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::PendingReview);
});

it('leaves a draft alone when it is edited', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", ['name' => 'Yeni Ad'])
        ->assertOk();

    // Nothing to re-review: nobody has looked at it yet.
    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::Draft);
});

it('still refuses to edit a listing a reviewer is holding', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)->postJson("/api/v1/seller/products/{$product->getKey()}/submit")->assertOk();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", ['name' => 'Değiştirilmiş Ad'])
        ->assertForbidden();
});

it('serves both the stored code and the display label for an attribute', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$product->getKey()}", [
            'attributes' => [['code' => 'color', 'value' => 'cream']],
        ])
        ->assertOk();

    $attribute = collect(
        $this->actingAs($this->sellerUser)
            ->getJson("/api/v1/seller/products/{$product->getKey()}")
            ->json('data.attributes')
    )->firstWhere('code', 'color');

    // A form populated from the label would match none of its own options and wipe the
    // attribute on the next save.
    expect($attribute['value'])->toBe('cream')
        ->and($attribute['display'])->toBe('Krem');
});

it('offers nothing to submit on a listing that is already published', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    publishListing($product, $this->sellerUser, $this->operator);

    // Resubmitting a live listing would take it off sale for no benefit at all.
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$product->getKey()}/submit")
        ->assertForbidden();

    expect($product->fresh()->moderation_status)->toBe(ModerationStatus::Approved);
});

it('shows the seller a price for a listing that is not on sale yet', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    $product->skus()->update(['status' => SkuStatus::Draft->value]);

    $response = $this->actingAs($this->sellerUser)->getJson('/api/v1/seller/products')->assertOk();

    // "from_price" is deliberately null — nothing is purchasable — but the seller's own
    // list still has to show what they priced it at.
    expect($response->json('data.0.from_price'))->toBeNull()
        ->and($response->json('data.0.lowest_price.amount_minor'))->toBe(4_890_000);
});

it('never lets an unpurchasable price reach the storefront', function (): void {
    $product = Product::factory()->forSeller($this->seller)->complete($this->seller)->create();

    publishListing($product, $this->sellerUser, $this->operator);
    $product->skus()->update(['status' => SkuStatus::Paused->value]);

    // The catalogue drops the listing entirely rather than quoting a price nobody can
    // pay; `lowest_price` exists for internal screens and must not resurrect it.
    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/catalog/products/{$product->slug}")->assertNotFound();
});

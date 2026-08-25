<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Catalog\Models\Category;
use App\Domains\Matching\Enums\EmbeddingSource;
use App\Domains\Matching\Models\ProductEmbedding;
use App\Domains\Matching\Services\CandidateQuery;
use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Enums\StockPolicy;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Products\Models\ProductSku;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Matching, against a fixed catalogue whose right answers are known.
 *
 * The benchmark this phase's gate asks for. Nine products with deliberate differences —
 * two sofas of different widths and prices, a sofa nobody can buy, a coffee table, a rug,
 * a wardrobe in a different room — and each test names the answer it expects and why.
 *
 * A matching system is easy to make *look* like it works: return the five cheapest things
 * in a category and most of the time nobody notices. What separates that from the real
 * thing is behaviour at the edges, so most of what follows is edges — the out-of-stock
 * variant, the product two centimetres too wide, the category that does not exist.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();

    // Everything routes to the simulator, whose embeddings are deterministic: similar
    // words come out near each other, so a fixture's expected answer is stable.
    makeAiRoute(AiTask::TextEmbedding, ['credit_cost' => 0, 'max_attempts' => 1]);
    makeAiRoute(AiTask::ProductMatchRerank, ['credit_cost' => 0, 'max_attempts' => 1]);

    [$this->seller] = makeApprovedSeller('Eşleştirme Test A.Ş.', 'eslestirme-test');

    $this->embedder = app(ProductEmbedder::class);
    $this->candidates = app(CandidateQuery::class);

    $this->sofaCategory = makeCategory('Kanepe', 'kanepe', 'living_room');
    $this->tableCategory = makeCategory('Sehpa', 'sehpa', 'living_room');
    $this->wardrobeCategory = makeCategory('Gardırop', 'gardirop', 'bedroom');

    // --- the benchmark catalogue ------------------------------------------------
    $this->narrowSofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'İskandinav meşe kanepe',
        'description' => 'Açık renk boucle kumaş, meşe ayaklı üçlü kanepe.',
        'price_minor' => 3_490_000,
        'width_mm' => 2_100,
    ]);

    $this->wideSofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'Geniş köşe kanepe',
        'description' => 'Altı kişilik köşe kanepe, koyu gri.',
        'price_minor' => 5_900_000,
        'width_mm' => 3_200,
    ]);

    $this->cheapSofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'Ekonomik ikili kanepe',
        'description' => 'Küçük daireler için ikili kanepe.',
        'price_minor' => 1_290_000,
        'width_mm' => 1_600,
    ]);

    $this->soldOutSofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'Tükenen kanepe',
        'description' => 'Meşe ayaklı üçlü kanepe, açık renk.',
        'price_minor' => 3_200_000,
        'width_mm' => 2_000,
        'stock_quantity' => 0,
    ]);

    $this->coffeeTable = makeProduct($this->seller, $this->tableCategory, [
        'name' => 'Meşe orta sehpa',
        'description' => 'Masif meşe orta sehpa.',
        'price_minor' => 890_000,
        'width_mm' => 900,
    ]);

    $this->wardrobe = makeProduct($this->seller, $this->wardrobeCategory, [
        'name' => 'Dört kapılı gardırop',
        'description' => 'Yatak odası için dört kapılı gardırop.',
        'price_minor' => 4_100_000,
        'width_mm' => 2_400,
    ]);

    foreach ([$this->narrowSofa, $this->wideSofa, $this->cheapSofa, $this->soldOutSofa, $this->coffeeTable, $this->wardrobe] as $product) {
        $this->embedder->embed($product);
    }
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('gives every listable product a vector', function (): void {
    expect(ProductEmbedding::query()->count())->toBe(6)
        ->and(ProductEmbedding::query()->ofSource(EmbeddingSource::Text)->count())->toBe(6);
});

it('does not embed the same text twice', function (): void {
    $before = ProductEmbedding::query()->where('product_id', $this->narrowSofa->getKey())->firstOrFail();

    // The common case on a nightly pass, and the reason the input is hashed: a catalogue
    // that has not changed must not cost a provider call per product.
    $again = $this->embedder->embed($this->narrowSofa->fresh());

    expect($again)->toBeNull()
        ->and(ProductEmbedding::query()->where('product_id', $this->narrowSofa->getKey())->firstOrFail()->updated_at)
        ->toEqual($before->updated_at);
});

it('re-embeds when the description changes', function (): void {
    $this->narrowSofa->forceFill(['description' => 'Tamamen farklı bir açıklama metni.'])->save();

    expect($this->embedder->embed($this->narrowSofa->fresh()))->not->toBeNull()
        ->and(ProductEmbedding::query()->where('product_id', $this->narrowSofa->getKey())->count())->toBe(1);
});

it('keeps the seller and the delivery terms out of the embedded text', function (): void {
    $text = $this->embedder->textFor($this->narrowSofa);

    /*
     * Two sofas from the same shop must not be similar *because* of the shop. The text is
     * assembled from what describes the product, in a fixed order, with the category first
     * because what comes first carries more weight in a summary.
     */
    expect($text)->toStartWith('Kanepe')
        ->and($text)->toContain('İskandinav meşe kanepe')
        ->and($text)->not->toContain('Eşleştirme Test');
});

it('finds sofas for a sofa, and nothing else', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe modern meşe'),
        ['category' => 'kanepe'],
    );

    $names = $results->pluck('product_name')->all();

    // The wardrobe and the coffee table are nearer in some abstract sense than nothing at
    // all — which is exactly why the category filter is SQL rather than a hint.
    expect($names)->not->toContain('Dört kapılı gardırop')
        ->and($names)->not->toContain('Meşe orta sehpa')
        ->and($names)->toContain('İskandinav meşe kanepe');
});

it('never suggests something nobody can buy', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe meşe açık renk'),
        ['category' => 'kanepe'],
    );

    /*
     * The out-of-stock sofa is textually the closest thing in the catalogue to this query
     * — deliberately, because that is the case a similarity search gets wrong. Stock is a
     * fact and belongs in the WHERE clause.
     */
    expect($results->pluck('product_name')->all())->not->toContain('Tükenen kanepe');
});

it('refuses a product that is too wide for the wall', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe'),
        ['category' => 'kanepe', 'max_width_mm' => 2_200],
    );

    $names = $results->pluck('product_name')->all();

    // 2100mm fits, 3200mm does not. A model asked to respect this would sometimes; a
    // WHERE clause always does.
    expect($names)->toContain('İskandinav meşe kanepe')
        ->and($names)->not->toContain('Geniş köşe kanepe');
});

it('lets an unmeasured product through and flags nothing', function (): void {
    $unmeasured = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'Ölçüsüz kanepe',
        'description' => 'Ölçüleri girilmemiş bir kanepe.',
        'price_minor' => 2_000_000,
        'width_mm' => null,
    ]);

    $this->embedder->embed($unmeasured);

    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe'),
        ['category' => 'kanepe', 'max_width_mm' => 1_000],
    );

    /*
     * A judgement rather than an oversight. Most of the catalogue will lack dimensions for
     * a long time, and excluding everything unmeasured would empty the results for exactly
     * the customer who took the trouble to measure their room.
     */
    expect($results->pluck('product_name')->all())->toContain('Ölçüsüz kanepe');
});

it('respects a budget ceiling', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe'),
        ['category' => 'kanepe', 'max_price_minor' => 2_000_000],
    );

    $names = $results->pluck('product_name')->all();

    expect($names)->toContain('Ekonomik ikili kanepe')
        ->and($names)->not->toContain('İskandinav meşe kanepe')
        ->and($names)->not->toContain('Geniş köşe kanepe');
});

it('returns nothing for a category that does not exist', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('avize'),
        ['category' => 'avize'],
    );

    /*
     * The important negative. Silently dropping an unmatched category would let the search
     * fall back to "the nearest products in the whole catalogue", which is how a plan
     * asking for a chandelier ends up recommending a wardrobe — and nothing would look
     * wrong.
     */
    expect($results)->toBeEmpty();
});

it('matches a category however it was capitalised or accented', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe'),
        ['category' => 'KANEPE'],
    );

    // The planner writes Turkish prose; the catalogue holds slugs. Neither side should
    // have to know how the other capitalises.
    expect($results)->not->toBeEmpty();
});

it('keeps a bedroom wardrobe out of a living room', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('gardırop'),
        ['category' => 'gardirop', 'room_type' => 'living_room'],
    );

    expect($results)->toBeEmpty();

    // And the same query in the room it belongs to does find it.
    $inBedroom = $this->candidates->nearest(
        $this->embedder->embedQuery('gardırop'),
        ['category' => 'gardirop', 'room_type' => 'bedroom'],
    );

    expect($inBedroom->pluck('product_name')->all())->toContain('Dört kapılı gardırop');
});

it('excludes products already chosen for another placement', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('kanepe'),
        ['category' => 'kanepe', 'exclude_product_ids' => [$this->narrowSofa->getKey()]],
    );

    // Two placements that both want a sofa should produce two different sofas.
    expect($results->pluck('product_id')->all())->not->toContain($this->narrowSofa->getKey());
});

it('ranks the closest description first', function (): void {
    $results = $this->candidates->nearest(
        $this->embedder->embedQuery('İskandinav meşe boucle kanepe'),
        ['category' => 'kanepe'],
    );

    // The fake embedding is word-overlap based, so "İskandinav meşe boucle" lands nearest
    // the sofa whose description contains those words. Nonsense as an embedding; exactly
    // right as a fixture for asserting that ordering follows meaning rather than price.
    expect($results->first()?->product_name)->toBe('İskandinav meşe kanepe');
});

it('reports similarity as a bounded percentage', function (): void {
    expect($this->candidates->similarityBps(0.0))->toBe(10_000)
        ->and($this->candidates->similarityBps(1.0))->toBe(0)
        // Cosine distance runs to 2; a similarity below nothing is not a thing anybody
        // can read, so it is clamped rather than allowed negative.
        ->and($this->candidates->similarityBps(1.8))->toBe(0);
});

/** A category in the tree, with its materialised path. */
function makeCategory(string $name, string $slug, ?string $roomType): Category
{
    $category = Category::query()->create([
        'name' => $name,
        'slug' => $slug,
        'position' => 0,
        'is_active' => true,
        'room_type' => $roomType,
    ]);

    // The materialised path is maintained by the taxonomy service rather than mass
    // assigned; a root category is its own slug.
    $category->forceFill(['path' => $slug, 'depth' => 0])->save();

    return $category;
}

/**
 * An approved, purchasable product with one offer.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeProduct(object $seller, Category $category, array $attributes): Product
{
    $product = Product::query()->create([
        'organization_id' => $seller->organization_id,
        'primary_category_id' => $category->getKey(),
        'name' => $attributes['name'],
        'slug' => Str::slug($attributes['name']).'-'.Str::lower(Str::random(6)),
        'product_type' => 'simple',
        'description' => $attributes['description'] ?? null,
    ]);

    $product->forceFill([
        'status' => ProductStatus::Active,
        'moderation_status' => ModerationStatus::Approved,
        'published_at' => now(),
    ])->save();

    $sku = ProductSku::query()->create([
        'product_id' => $product->getKey(),
        'seller_id' => $seller->getKey(),
        'sku' => Str::upper(Str::random(10)),
        'currency' => 'TRY',
        'list_price_minor' => $attributes['price_minor'],
        'tax_rate_bps' => 2_000,
        'stock_policy' => StockPolicy::Track,
        'stock_quantity' => $attributes['stock_quantity'] ?? 10,
    ]);

    $sku->forceFill(['status' => SkuStatus::Active])->save();

    if (($attributes['width_mm'] ?? null) !== null) {
        ProductDimension::query()->create([
            'sku_id' => $sku->getKey(),
            'width_mm' => $attributes['width_mm'],
            'height_mm' => 850,
            'depth_mm' => 900,
        ]);
    }

    return $product->fresh(['skus.dimensions', 'primaryCategory']);
}

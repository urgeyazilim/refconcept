<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Catalog\Models\Style;
use App\Domains\Commerce\Models\Favorite;
use App\Domains\Commerce\Services\CatalogSearch;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Services\ProductEmbedder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Finding things, and keeping the ones you liked.
 *
 * Search is the hardest thing here to test honestly, because "did it return the right
 * products" is a judgement and a fixture cannot make it. What a fixture *can* prove is
 * the machinery: that all three methods contribute, that a fusion of three weak agreements
 * beats one strong signal, that a filter which matches nothing returns nothing, and that
 * the facet counts describe the whole result rather than the page.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();

    makeAiRoute(AiTask::TextEmbedding, ['credit_cost' => 0, 'max_attempts' => 1]);

    $this->search = app(CatalogSearch::class);
    $this->embedder = app(ProductEmbedder::class);

    [$this->seller] = makeApprovedSeller('Arama Test A.Ş.', 'arama-test');

    $this->sofaCategory = makeCategory('Kanepe', 'kanepe', 'living_room');
    $this->rugCategory = makeCategory('Halı', 'hali', 'living_room');

    $modern = Style::query()->create(['code' => 'modern', 'name' => 'Modern', 'position' => 1]);
    $classic = Style::query()->create(['code' => 'klasik', 'name' => 'Klasik', 'position' => 2]);

    $this->sofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'İskandinav meşe kanepe',
        'description' => 'Açık renk boucle kumaş, meşe ayaklı üçlü kanepe.',
        'price_minor' => 3_490_000,
        'width_mm' => 2_100,
    ]);

    $this->sofa->forceFill(['style_id' => $modern->getKey()])->save();

    $this->cheapSofa = makeProduct($this->seller, $this->sofaCategory, [
        'name' => 'Ekonomik ikili kanepe',
        'description' => 'Küçük daireler için ikili kanepe.',
        'price_minor' => 1_290_000,
        'width_mm' => 1_600,
    ]);

    $this->cheapSofa->forceFill(['style_id' => $classic->getKey()])->save();

    $this->rug = makeProduct($this->seller, $this->rugCategory, [
        'name' => 'Bergama halı',
        'description' => 'El dokuma yün halı, kırmızı zemin.',
        'price_minor' => 690_000,
        'width_mm' => 2_000,
    ]);

    $this->rug->forceFill(['style_id' => $classic->getKey()])->save();

    foreach ([$this->sofa, $this->cheapSofa, $this->rug] as $product) {
        $this->embedder->embed($product->fresh());
    }

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

it('finds a product by its name', function (): void {
    $ranked = $this->search->rank('Bergama');

    expect($ranked)->not->toBeEmpty()
        ->and($ranked[0])->toBe($this->rug->getKey());
});

it('finds a product through a misspelling', function (): void {
    /*
     * Trigram similarity rather than a substring match. A search box receives "kanepr" and
     * "bergma" constantly, and a customer who mistypes once and gets nothing concludes the
     * shop does not stock it.
     */
    $ranked = $this->search->rank('Bergma');

    expect($ranked)->toContain($this->rug->getKey());
});

it('finds a product by words from its description', function (): void {
    // "boucle" is nowhere in a product name; the maintained tsvector is what finds it.
    $ranked = $this->search->rank('boucle');

    expect($ranked)->toContain($this->sofa->getKey());
});

it('returns nothing when nothing matches', function (): void {
    $ranked = $this->search->rank('zzzzqqqq');

    /*
     * The important negative. A search that quietly falls back to the whole catalogue
     * looks like it worked and is the reason customers stop trusting a search box.
     */
    expect($ranked)->toBeEmpty();
});

it('ranks a product every method agrees on above one only a single method found', function (): void {
    /*
     * The reason fusion is by rank rather than by score. "kanepe" is in the sofa's name,
     * its description and its vector; it is in neither of the rug's. Three weak agreements
     * beat one strong signal, which is exactly what reciprocal rank fusion is for.
     */
    $ranked = $this->search->rank('kanepe');

    $sofaPosition = array_search($this->sofa->getKey(), $ranked, true);
    $rugPosition = array_search($this->rug->getKey(), $ranked, true);

    expect($sofaPosition)->not->toBeFalse()
        ->and($rugPosition === false || $sofaPosition < $rugPosition)->toBeTrue();
});

it('serves search results through the public catalogue', function (): void {
    $response = $this->getJson('/api/v1/catalog/products?search=Bergama')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Bergama halı');
});

it('counts facets over the whole result, not the page', function (): void {
    $response = $this->getJson('/api/v1/catalog/products?per_page=1')->assertOk();

    $categories = collect($response->json('facets.categories'));

    /*
     * One product on the page, three in the result. A facet counted after pagination would
     * say "Kanepe (1)" and be useless — the whole point of a count is to tell somebody
     * what is behind a filter they have not clicked yet.
     */
    expect($response->json('data'))->toHaveCount(1)
        ->and($categories->firstWhere('value', 'kanepe')['count'])->toBe(2)
        ->and($categories->firstWhere('value', 'hali')['count'])->toBe(1);
});

it('counts styles as facets', function (): void {
    $response = $this->getJson('/api/v1/catalog/products')->assertOk();

    $styles = collect($response->json('facets.styles'));

    expect($styles->firstWhere('value', 'modern')['count'])->toBe(1)
        ->and($styles->firstWhere('value', 'klasik')['count'])->toBe(2);
});

it('offers only price bands that contain something', function (): void {
    $response = $this->getJson('/api/v1/catalog/products')->assertOk();

    $bands = collect($response->json('facets.price_bands'));

    /*
     * An empty band is dropped rather than shown at zero. A filter that returns nothing is
     * a filter that should not have been offered, and four of them in a row teaches
     * somebody the filters do not work.
     */
    expect($bands)->not->toBeEmpty()
        ->and($bands->pluck('count')->every(fn (int $count): bool => $count > 0))->toBeTrue();
});

it('narrows facets to what the other filters left', function (): void {
    $response = $this->getJson('/api/v1/catalog/products?category=hali')->assertOk();

    $styles = collect($response->json('facets.styles'));

    // Only the rug survives the category filter, so only its style is worth offering.
    expect($response->json('data'))->toHaveCount(1)
        ->and($styles)->toHaveCount(1)
        ->and($styles->first()['value'])->toBe('klasik');
});

it('keeps a favourite and gives it back', function (): void {
    $this->actingAs($this->customer)
        ->postJson('/api/v1/favorites/'.$this->sofa->getKey())
        ->assertCreated()
        ->assertJsonPath('data.is_favorite', true);

    $response = $this->actingAs($this->customer)->getJson('/api/v1/favorites')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($this->sofa->getKey());
});

it('treats favouriting twice as favouriting once', function (): void {
    foreach ([1, 2] as $ignored) {
        $this->actingAs($this->customer)
            ->postJson('/api/v1/favorites/'.$this->sofa->getKey())
            ->assertCreated();
    }

    // A double tap is a no-op rather than two rows and a count that reads wrong.
    expect(Favorite::query()->count())->toBe(1);
});

it('removes a favourite', function (): void {
    $this->actingAs($this->customer)->postJson('/api/v1/favorites/'.$this->sofa->getKey());

    $this->actingAs($this->customer)
        ->deleteJson('/api/v1/favorites/'.$this->sofa->getKey())
        ->assertOk()
        ->assertJsonPath('data.is_favorite', false);

    expect(Favorite::query()->count())->toBe(0);
});

it('answers which of a page are favourited in one request', function (): void {
    $this->actingAs($this->customer)->postJson('/api/v1/favorites/'.$this->sofa->getKey());

    $response = $this->actingAs($this->customer)
        ->postJson('/api/v1/favorites/check', [
            'product_ids' => [$this->sofa->getKey(), $this->rug->getKey()],
        ])
        ->assertOk();

    /*
     * One request for a whole results page. A flag on every product in every catalogue
     * response would mean a join on a listing anonymous visitors also read, for a field
     * only signed-in ones can use.
     */
    expect($response->json('data'))->toBe([$this->sofa->getKey()]);
});

it('drops a withdrawn product from the favourites list', function (): void {
    $this->actingAs($this->customer)->postJson('/api/v1/favorites/'.$this->sofa->getKey());

    $this->sofa->forceFill(['status' => 'archived'])->save();

    $response = $this->actingAs($this->customer)->getJson('/api/v1/favorites')->assertOk();

    /*
     * Dropped rather than shown greyed out. A favourites page is a shortlist somebody is
     * shopping from, and filling it with things nobody can buy makes it a worse shortlist.
     * The row survives, so re-listing the product brings it back.
     */
    expect($response->json('data'))->toBeEmpty()
        ->and(Favorite::query()->count())->toBe(1);
});

it('will not favourite something the catalogue does not show', function (): void {
    $this->rug->forceFill(['status' => 'draft', 'moderation_status' => 'draft', 'published_at' => null])->save();

    $this->actingAs($this->customer)
        ->postJson('/api/v1/favorites/'.$this->rug->getKey())
        ->assertNotFound();
});

it('keeps one customer favourites out of another list', function (): void {
    $other = User::factory()->create();

    $this->actingAs($this->customer)->postJson('/api/v1/favorites/'.$this->sofa->getKey());

    $response = $this->actingAs($other)->getJson('/api/v1/favorites')->assertOk();

    expect($response->json('data'))->toBeEmpty();
});

it('refuses favourites to somebody who is not signed in', function (): void {
    $this->postJson('/api/v1/favorites/'.$this->sofa->getKey())->assertUnauthorized();
    $this->getJson('/api/v1/favorites')->assertUnauthorized();
});

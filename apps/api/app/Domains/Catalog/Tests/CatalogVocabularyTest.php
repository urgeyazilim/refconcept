<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Style;
use App\Domains\Catalog\Services\CatalogCoverage;
use App\Domains\Catalog\Services\StyleAffinity;
use App\Domains\Products\Models\Product;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

/**
 * The vocabulary a guided design is built on.
 *
 * A customer picking "Lüks" from a row of pictures is only as good as the catalogue's
 * ability to answer, and for a long time it could not: `style_id` was optional, sellers
 * left it empty, and the twelve products carrying one did so because a seeder said so.
 * These cover the three pieces that make the answer possible — a product belonging to more
 * than one style, styles having neighbours, and the shop being able to say what it stocks
 * before anybody is offered it.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    app(StyleAffinity::class)->forget();
    app(CatalogCoverage::class)->forget();
});

describe('style affinity', function (): void {
    it('treats a style as a perfect match for itself', function (): void {
        expect(app(StyleAffinity::class)->between('luxury', 'luxury'))->toBe(10_000);
    });

    it('ranks a neighbouring style below an exact one but above nothing', function (): void {
        $affinity = app(StyleAffinity::class);

        /*
         * The whole reason this exists. Twelve products in the catalogue means a strict
         * `WHERE style = luxury` returns nothing, and a customer reads nothing as a broken
         * page rather than as a thin shop. Classic ranks just behind luxury; industrial
         * does not rank at all.
         */
        expect($affinity->between('luxury', 'classic'))->toBeGreaterThan(0)
            ->and($affinity->between('luxury', 'classic'))->toBeLessThan(10_000)
            ->and($affinity->between('luxury', 'industrial'))->toBe(0);
    });

    it('reads the same in both directions', function (): void {
        $affinity = app(StyleAffinity::class);

        // Adjacency is a statement about two styles, not about the order they were asked
        // in. Stored both ways round so a lookup never has to try the pair reversed.
        expect($affinity->between('modern', 'minimal'))
            ->toBe($affinity->between('minimal', 'modern'));
    });

    it('scores an untagged product at nothing rather than failing', function (): void {
        // A listing from before style was required still has to be searchable. It ranks
        // last; it does not break the search.
        expect(app(StyleAffinity::class)->between('modern', null))->toBe(0);
    });
});

describe('catalogue coverage', function (): void {
    it('reports nothing for a category the shop does not stock', function (): void {
        /*
         * The question that had to be asked and never was. The catalogue stocks no
         * television units, so a plan calling for one produced no match, no product
         * photograph and — before the renderer was told to stop — a beautifully drawn
         * television unit nobody could buy.
         */
        $coverage = app(CatalogCoverage::class);

        expect($coverage->inCategory('tv-unitesi'))->toBe(0)
            ->and($coverage->verdict('tv-unitesi', 'modern'))
            ->toBe(['available' => false, 'exact' => false, 'count' => 0]);
    });

    it('counts a published listing and separates it by style', function (): void {
        [$seller] = makeApprovedSeller('Kapsam Test A.Ş.', 'kapsam-test');

        $sofa = makeProduct($seller, Category::query()->where('slug', 'kanepe')->firstOrFail(), [
            'name' => 'Modern üçlü kanepe',
            'description' => 'Açık renk kumaş üçlü kanepe.',
            'price_minor' => 3_490_000,
            'width_mm' => 2_100,
        ]);

        $sofa->styles()->sync([
            Style::query()->where('code', 'modern')->value('id') => [
                'strength_bps' => 10_000, 'is_primary' => true,
            ],
        ]);

        app(CatalogCoverage::class)->forget();
        $coverage = app(CatalogCoverage::class);

        expect($coverage->inCategory('kanepe'))->toBe(1)
            // Exactly right for modern, and near enough for minimal, which is a neighbour.
            ->and($coverage->inCategoryForStyle('kanepe', 'modern'))->toBe(1)
            ->and($coverage->inCategoryForStyle('kanepe', 'minimal'))->toBe(1)
            // Nothing for luxury, which is not — so the customer is told, rather than
            // shown a modern sofa and left to wonder.
            ->and($coverage->inCategoryForStyle('kanepe', 'luxury'))->toBe(0);
    });

    it('says a category is stocked but not in the chosen style', function (): void {
        [$seller] = makeApprovedSeller('Stil Test A.Ş.', 'stil-test');

        $sofa = makeProduct($seller, Category::query()->where('slug', 'kanepe')->firstOrFail(), [
            'name' => 'Endüstriyel kanepe',
            'description' => 'Deri ve metal ayaklı kanepe.',
            'price_minor' => 2_890_000,
            'width_mm' => 2_000,
        ]);

        $sofa->styles()->sync([
            Style::query()->where('code', 'industrial')->value('id') => [
                'strength_bps' => 10_000, 'is_primary' => true,
            ],
        ]);

        app(CatalogCoverage::class)->forget();

        /*
         * The middle state, and the reason this is not a boolean. "We do not sell these"
         * sends a customer to a different plan; "we sell these but not in classic" sends
         * them to a different style, or to waiting. Collapsing the two would lose the only
         * part they can act on.
         */
        expect(app(CatalogCoverage::class)->verdict('kanepe', 'classic'))
            ->toBe(['available' => true, 'exact' => false, 'count' => 0]);
    });

    it('counts a listing once however many offers it carries', function (): void {
        [$seller] = makeApprovedSeller('Çoklu SKU A.Ş.', 'coklu-sku');

        $sofa = makeProduct($seller, Category::query()->where('slug', 'kanepe')->firstOrFail(), [
            'name' => 'İki bedenli kanepe',
            'description' => 'Aynı kanepenin iki ölçüsü.',
            'price_minor' => 3_100_000,
            'width_mm' => 2_200,
        ]);

        // A second offer on the same listing. One thing to buy, not two — a catalogue that
        // counted SKUs would report itself deeper than it is, which is the one direction
        // this number must never be wrong in.
        $sofa->skus()->create([
            'seller_id' => $seller->getKey(),
            'sku' => 'IKINCI-BEDEN',
            'currency' => 'TRY',
            'list_price_minor' => 3_600_000,
            'tax_rate_bps' => 2_000,
            'stock_policy' => 'track',
            'stock_quantity' => 4,
        ])->forceFill(['status' => 'active'])->save();

        app(CatalogCoverage::class)->forget();

        expect(app(CatalogCoverage::class)->inCategory('kanepe'))->toBe(1);
    });
});

describe('a product belongs to more than one style', function (): void {
    it('keeps a primary and its secondaries, primary first', function (): void {
        [$seller] = makeApprovedSeller('Çoklu Stil A.Ş.', 'coklu-stil');

        $product = Product::factory()->forSeller($seller)->create();

        $minimal = Style::query()->where('code', 'minimal')->value('id');
        $scandinavian = Style::query()->where('code', 'scandinavian')->value('id');

        /*
         * A plain oak sideboard is credibly minimal and scandinavian, and a seller made to
         * pick one loses half of what makes it findable. The strengths let matching prefer
         * "a minimal sideboard" over "a sideboard that is also a bit minimal" without
         * either becoming invisible.
         */
        $product->styles()->sync([
            $minimal => ['strength_bps' => 10_000, 'is_primary' => true],
            $scandinavian => ['strength_bps' => 5_000, 'is_primary' => false],
        ]);

        $styles = $product->fresh()?->styles;

        expect($styles)->toHaveCount(2)
            ->and($styles?->first()?->code)->toBe('minimal')
            ->and($styles?->first()?->pivot->is_primary)->toBeTrue();
    });

    it('refuses two primaries for one product', function (): void {
        [$seller] = makeApprovedSeller('Tek Birincil A.Ş.', 'tek-birincil');

        $product = Product::factory()->forSeller($seller)->create();

        $first = Style::query()->where('code', 'modern')->value('id');
        $second = Style::query()->where('code', 'classic')->value('id');

        // Enforced in the database rather than in a service, because "what is this,
        // mainly?" has to have one answer however the row was written.
        expect(fn () => $product->styles()->sync([
            $first => ['strength_bps' => 10_000, 'is_primary' => true],
            $second => ['strength_bps' => 10_000, 'is_primary' => true],
        ]))->toThrow(QueryException::class);
    });
});

it('offers the palettes with their swatches', function (): void {
    $response = $this->getJson('/api/v1/catalog/vocabulary')->assertOk();

    $palettes = $response->json('data.palettes');

    /*
     * Nobody picks "taupe". They pick "sıcak nötr" and mean six colours at once, and they
     * pick it by looking at it — so the hex values travel with the palette rather than the
     * client having to join them back to the colour list.
     */
    expect($palettes)->toHaveCount(5)
        ->and(collect($palettes)->pluck('code')->all())->toContain('warm-neutral')
        ->and($palettes[0]['swatches'])->not->toBeEmpty()
        ->and($palettes[0]['swatches'][0]['hex'])->toStartWith('#');
});

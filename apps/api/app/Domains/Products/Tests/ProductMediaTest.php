<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Attribute;
use App\Domains\Catalog\Models\Category;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductImageStorage;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product imagery.
 *
 * Two things are worth protecting here and neither is obvious from the endpoint
 * signatures. The first is the single-cover invariant: a partial unique index means
 * any reorder that passes through a state with two rows at position 0 fails, so the
 * service has to park rows out of the way rather than update them in place. The
 * second is that this is the one disk in the system that is anonymously readable, so
 * what may be written to it is checked against the decoded bytes and not the
 * client's headers.
 */
beforeEach(function (): void {
    Storage::fake('s3-public');
    config()->set('refconcept.storage.public_disk', 's3-public');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);

    $this->category = Category::query()->where('slug', 'kanepe')->firstOrFail();

    [$this->seller, $this->sellerUser] = makeApprovedSeller('Atlas Mobilya', 'atlas-mobilya');
    [, $this->rivalUser] = makeApprovedSeller('Nova Yaşam', 'nova-yasam');

    $this->product = Product::factory()->forSeller($this->seller)->create([
        'primary_category_id' => $this->category->getKey(),
    ]);
});

/** The first permitted value of a selectable attribute, whatever the seeder chose. */
function firstAttributeValue(string $code): string
{
    return Attribute::query()
        ->where('code', $code)
        ->firstOrFail()
        ->values()
        ->firstOrFail()
        ->value;
}

/** @return array<int, ProductMedia> */
function uploadImages(int $count): array
{
    /** @var array<int, ProductMedia> $created */
    $created = [];

    foreach (range(1, $count) as $index) {
        test()->actingAs(test()->sellerUser)
            ->postJson('/api/v1/seller/products/'.test()->product->getKey().'/media', [
                'file' => UploadedFile::fake()->image("gorsel-{$index}.jpg", 1200, 900),
            ])
            ->assertCreated();
    }

    foreach (ProductMedia::query()->orderBy('position')->get() as $media) {
        $created[] = $media;
    }

    return $created;
}

it('stores an uploaded image on the public disk and makes it the cover', function (): void {
    $response = $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            'file' => UploadedFile::fake()->image('kanepe.jpg', 1600, 1200),
            'alt_text' => 'Bouclé kumaş üç kişilik kanepe',
        ]);

    $response->assertCreated()->assertJsonPath('data.media.0.is_cover', true);

    $media = ProductMedia::query()->firstOrFail();

    expect($media->position)->toBe(0)
        ->and($media->width)->toBe(1600)
        ->and($media->height)->toBe(1200)
        ->and($media->alt_text)->toBe('Bouclé kumaş üç kişilik kanepe')
        ->and($media->disk)->toBe('s3-public');

    Storage::disk('s3-public')->assertExists($media->storage_path);
});

it('names the stored object from the detected type, not the uploaded filename', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            // A filename a careless static host would happily execute.
            'file' => UploadedFile::fake()->image('shell.php.png', 400, 400),
        ])
        ->assertCreated();

    $path = ProductMedia::query()->firstOrFail()->storage_path;

    expect($path)->toEndWith('.png')
        ->and($path)->not->toContain('shell')
        ->and($path)->not->toContain('.php');
});

it('refuses a file that is not a decodable image', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            'file' => UploadedFile::fake()->create('katalog.pdf', 120, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(ProductMedia::query()->count())->toBe(0);
});

it('refuses an SVG even though it is an image', function (): void {
    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            'file' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])
        ->assertStatus(422);

    expect(ProductMedia::query()->count())->toBe(0);
});

it('caps the number of images per product', function (): void {
    uploadImages(ProductImageStorage::MAX_PER_PRODUCT);

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            'file' => UploadedFile::fake()->image('bir-fazla.jpg'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(ProductMedia::query()->count())->toBe(ProductImageStorage::MAX_PER_PRODUCT);
});

it('never lets another seller touch the gallery', function (): void {
    $media = uploadImages(1)[0];

    $this->actingAs($this->rivalUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media", [
            'file' => UploadedFile::fake()->image('sizma.jpg'),
        ])
        ->assertForbidden();

    $this->actingAs($this->rivalUser)
        ->deleteJson("/api/v1/seller/products/{$this->product->getKey()}/media/{$media->getKey()}")
        ->assertForbidden();

    expect(ProductMedia::query()->count())->toBe(1);
});

it('does not let a media id from another product be edited through this one', function (): void {
    $other = Product::factory()->forSeller($this->seller)->create([
        'primary_category_id' => $this->category->getKey(),
    ]);

    $media = uploadImages(1)[0];

    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$other->getKey()}/media/{$media->getKey()}", [
            'alt_text' => 'Yanlış ürün',
        ])
        ->assertNotFound();
});

it('reorders the gallery without ever holding two covers', function (): void {
    $media = uploadImages(3);

    $reversed = array_reverse(array_map(
        static fn (ProductMedia $item): string => (string) $item->getKey(),
        $media,
    ));

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media/reorder", [
            'media' => $reversed,
        ])
        ->assertOk();

    $positions = ProductMedia::query()->orderBy('position')->pluck('id')->all();

    expect($positions)->toBe($reversed)
        ->and(ProductMedia::query()->where('position', 0)->count())->toBe(1);
});

it('keeps images the client forgot to mention at the end of the order', function (): void {
    $media = uploadImages(3);

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media/reorder", [
            'media' => [(string) $media[2]->getKey()],
        ])
        ->assertOk();

    expect(ProductMedia::query()->orderBy('position')->value('id'))
        ->toBe((string) $media[2]->getKey())
        ->and(ProductMedia::query()->count())->toBe(3);
});

it('ignores ids that belong to a different product', function (): void {
    $media = uploadImages(2);

    $this->actingAs($this->sellerUser)
        ->postJson("/api/v1/seller/products/{$this->product->getKey()}/media/reorder", [
            'media' => [(string) $media[1]->getKey(), (string) Str::uuid7()],
        ])
        ->assertOk();

    expect(ProductMedia::query()->orderBy('position')->pluck('id')->all())
        ->toBe([(string) $media[1]->getKey(), (string) $media[0]->getKey()]);
});

it('promotes the next image when the cover is deleted', function (): void {
    $media = uploadImages(3);

    $this->actingAs($this->sellerUser)
        ->deleteJson("/api/v1/seller/products/{$this->product->getKey()}/media/{$media[0]->getKey()}")
        ->assertOk();

    $remaining = ProductMedia::query()->orderBy('position')->get();

    expect($remaining)->toHaveCount(2)
        ->and($remaining->first()->position)->toBe(0)
        ->and($remaining->first()->id)->toBe((string) $media[1]->getKey());

    Storage::disk('s3-public')->assertMissing($media[0]->storage_path);
});

it('unblocks submission once a listing has an image', function (): void {
    $this->product->skus()->create([
        'seller_id' => $this->seller->getKey(),
        'sku' => 'ATL-001',
        'list_price_minor' => 4_890_000,
        'stock_quantity' => 5,
    ])->dimensions()->create([
        'width_mm' => 2200,
        'depth_mm' => 950,
        'height_mm' => 780,
    ]);

    // Everything the category demands except the photograph, so the image is the one
    // thing standing between this listing and review.
    $this->actingAs($this->sellerUser)
        ->patchJson("/api/v1/seller/products/{$this->product->getKey()}", [
            'description' => 'Bouclé kumaş, modüler oturma grubu.',
            'attributes' => [
                ['code' => 'color', 'value' => firstAttributeValue('color')],
                ['code' => 'material', 'value' => firstAttributeValue('material')],
            ],
        ])
        ->assertOk();

    $before = $this->actingAs($this->sellerUser)
        ->getJson("/api/v1/seller/products/{$this->product->getKey()}")
        ->json('meta');

    expect($before['missing_requirements'])->toBe(['En az bir ürün görseli'])
        ->and($before['can_submit'])->toBeFalse();

    uploadImages(1);

    $after = $this->actingAs($this->sellerUser)
        ->getJson("/api/v1/seller/products/{$this->product->getKey()}")
        ->json('meta');

    expect($after['missing_requirements'])->not->toContain('En az bir ürün görseli')
        ->and($after['can_submit'])->toBeTrue();
});

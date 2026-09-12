<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Products\Jobs\GenerateProductModel;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductModelStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * A product's 3D model: where it comes from, and who it must never displace.
 *
 * The planner draws a product as its own photograph cut out of its background, which works
 * and is flat. A mesh made from that same photograph is better to look at and worse to trust:
 * its far side was never photographed, so it is a likeness rather than the thing.
 *
 * Everything here is about keeping that distinction. A seller who has the real file from
 * their manufacturer outranks the likeness. A likeness is never used in a render. And the
 * whole feature is optional — a product without one is drawn the way every product was drawn
 * before this existed.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();
    Storage::fake('s3');
    Storage::fake('s3-public');

    makeAiRoute(AiTask::ProductModel, ['credit_cost' => 0, 'max_attempts' => 1]);

    [$this->seller] = makeApprovedSeller('Model Test A.Ş.', 'model-test');

    $this->models = app(ProductModelStorage::class);

    $this->product = makeProduct(
        $this->seller,
        makeCategory('Kanepe', 'kanepe', 'living_room'),
        ['name' => 'Üçlü kanepe', 'price_minor' => 100_000, 'width_mm' => 2_200],
    );

    // A photograph to work from. Without one there is nothing to convert.
    ProductMedia::query()->create([
        'product_id' => $this->product->getKey(),
        'type' => 'image',
        'disk' => 's3-public',
        'storage_path' => 'product-media/'.$this->product->getKey().'/cover.jpg',
        'original_name' => 'kanepe.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 120_000,
        'position' => 0,
    ]);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

/** A minimal file that starts the way a glTF binary starts. */
function glb(string $tail = 'x'): string
{
    return 'glTF'.str_repeat($tail, 64);
}

/**
 * Points the route at a real adapter, with fal.ai itself faked at the HTTP boundary.
 *
 * The simulator is what runs with no key, and what it answers is deliberately not stored.
 * Everything below this line is about the behaviour a key buys, so the test has to buy one —
 * and faking the provider's HTTP rather than the adapter means the adapter is under test too.
 */
function realProvider(): void
{
    $model = AiTaskRoute::query()->where('task', 'product_model')->firstOrFail()->primaryModel;

    $provider = $model?->provider;

    $provider?->forceFill(['driver' => 'fal'])->save();

    $provider?->credentials()->updateOrCreate(
        ['label' => 'test'],
        ['secret_encrypted' => 'fal-test-key', 'secret_hint' => 'tkey', 'is_active' => true],
    );

    Http::fake([
        'fal.run/*' => Http::response(['model_mesh' => ['url' => 'https://cdn.fal.test/mesh.glb']]),
        'cdn.fal.test/*' => Http::response(glb(), 200, ['Content-Type' => 'model/gltf-binary']),
    ]);
}

it('throws away what the simulator answers rather than storing it', function (): void {
    /*
     * With no key on file the task routes to the fake provider, which succeeds — that is its
     * job — and returns bytes that begin the way a glTF binary begins. Storing that would put
     * a file that is not a model of anything on the public bucket for every product ever
     * approved, and the planner would try to load each one and fall back to the photograph it
     * should have used in the first place.
     */
    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
    );

    expect(ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('type', 'model_3d')
        ->exists())->toBeFalse();
});

it('makes a model from the product photograph', function (): void {
    // Answered by something that is not the simulator, which is what a key buys.
    realProvider();

    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
    );

    $model = ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('type', 'model_3d')
        ->first();

    expect($model)->not->toBeNull()
        // Marked as generated, because the planner labels a likeness and a seller's file is
        // not one.
        ->and($model->source)->toBe('ai')
        ->and($model->mime_type)->toBe('model/gltf-binary')
        ->and(Storage::disk('s3-public')->exists((string) $model->storage_path))->toBeTrue();
});

it('leaves a seller their own file', function (): void {
    $this->models->storeGenerated($this->product, glb('a'));

    // The manufacturer's file, uploaded afterwards. It is the shape of the thing rather than
    // a guess at it, and nothing generated may stand in front of it.
    ProductMedia::query()->create([
        'product_id' => $this->product->getKey(),
        'type' => 'model_3d',
        'source' => 'seller',
        'disk' => 's3-public',
        'storage_path' => 'product-models/'.$this->product->getKey().'/seller.glb',
        'original_name' => 'kanepe.glb',
        'mime_type' => 'model/gltf-binary',
        'size_bytes' => 4_096,
        'position' => 99,
    ]);

    expect($this->models->bestFor($this->product)?->source)->toBe('seller');

    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
    );

    // And the job did not spend anything trying to improve on it.
    expect(FakeAiProvider::calls())->toBeEmpty();
});

it('keeps one generated model per product rather than a pile', function (): void {
    $first = $this->models->storeGenerated($this->product, glb('a'));
    $second = $this->models->storeGenerated($this->product, glb('b'));

    /*
     * A photograph that changed should get a mesh made from the new one, and the old mesh is
     * then a second answer to "what does this look like" — whichever the query returned first
     * would be the one the customer saw.
     */
    expect(ProductMedia::query()->where('product_id', $this->product->getKey())->where('type', 'model_3d')->count())
        ->toBe(1)
        ->and($second->storage_path)->not->toBe($first->storage_path)
        ->and(Storage::disk('s3-public')->exists((string) $first->storage_path))->toBeFalse();
});

it('lets an operator throw away a likeness without touching the seller file', function (): void {
    $seller = ProductMedia::query()->create([
        'product_id' => $this->product->getKey(),
        'type' => 'model_3d',
        'source' => 'seller',
        'disk' => 's3-public',
        'storage_path' => 'product-models/'.$this->product->getKey().'/seller.glb',
        'original_name' => 'kanepe.glb',
        'mime_type' => 'model/gltf-binary',
        'size_bytes' => 4_096,
        'position' => 99,
    ]);

    $this->models->storeGenerated($this->product, glb());

    // A sofa that came back with three arms is worse than no model at all, and the planner
    // falls back to the photograph cut-out.
    $this->models->discardGenerated($this->product);

    expect(ProductMedia::query()->whereKey($seller->getKey())->exists())->toBeTrue()
        ->and(ProductMedia::query()
            ->where('product_id', $this->product->getKey())
            ->where('source', 'ai')
            ->exists())->toBeFalse();
});

it('does nothing for a product with no photograph', function (): void {
    ProductMedia::query()->where('product_id', $this->product->getKey())->delete();

    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
    );

    // Nothing to convert, nothing spent, and the planner draws a box. Not an error.
    expect(FakeAiProvider::calls())->toBeEmpty()
        ->and(ProductMedia::query()->where('product_id', $this->product->getKey())->count())->toBe(0);
});

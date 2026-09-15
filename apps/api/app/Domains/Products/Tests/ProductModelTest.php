<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiCostRate;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Products\Enums\ProductStatus;
use App\Domains\Products\Jobs\GenerateProductModel;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductModelStorage;
use App\Domains\Products\Services\ProductViewTagger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
function realProvider(bool $fakeHttp = true, string $code = 'tripo3d/tripo/v2.5/image-to-3d'): void
{
    $model = AiTaskRoute::query()->where('task', 'product_model')->firstOrFail()->primaryModel;

    // Under a generator's own name: the adapter reads the family off the code, so this is
    // how each request shape is reached, and the test route's placeholder code reaches none.
    $model?->forceFill(['code' => $code])->save();

    $provider = $model?->provider;

    $provider?->forceFill(['driver' => 'fal'])->save();

    $provider?->credentials()->updateOrCreate(
        ['label' => 'test'],
        ['secret_encrypted' => 'fal-test-key', 'secret_hint' => 'tkey', 'is_active' => true],
    );

    // A test scripting its own answers says so: Http::fake keeps the first stub that matches,
    // so a success registered here would win over anything registered afterwards.
    if (! $fakeHttp) {
        return;
    }

    Http::fake([
        // fal's free file storage: an upload slot, then the bytes, then a CDN link.
        'rest.alpha.fal.ai/storage/upload/initiate*' => Http::response([
            'upload_url' => 'https://upload.fal.test/slot',
            'file_url' => 'https://v3b.fal.test/product.jpeg',
        ]),
        'upload.fal.test/*' => Http::response('', 200),
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
        app(ProductViewTagger::class),
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
        app(ProductViewTagger::class),
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

it('sends four sides to the generator when the photographs say which is which', function (): void {
    realProvider();

    /*
     * The whole point of labelling. Four views cost exactly what one costs, and given the
     * back the generator stops inventing one — so a labelled catalogue gets better meshes for
     * the same money, through a different endpoint.
     */
    foreach (['left' => 1, 'back' => 2, 'right' => 3] as $view => $position) {
        ProductMedia::query()->create([
            'product_id' => $this->product->getKey(),
            'type' => 'image',
            'view' => $view,
            'disk' => 's3-public',
            'storage_path' => 'product-media/'.$this->product->getKey().'/'.$view.'.jpg',
            'original_name' => $view.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120_000,
            'position' => $position,
        ]);
    }

    ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('position', 0)
        ->update(['view' => 'front']);

    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
        app(ProductViewTagger::class),
    );

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'multiview-to-3d')) {
            return false;
        }

        $body = $request->data();

        return str_contains((string) $body['front_image_url'], 'cover.jpg')
            && str_contains((string) $body['left_image_url'], 'left.jpg')
            && str_contains((string) $body['back_image_url'], 'back.jpg')
            && str_contains((string) $body['right_image_url'], 'right.jpg')
            // Never `auto_size`: the catalogue knows the sofa is 2200 mm because a seller
            // measured it, and a mesh scaled to a guess is worse than a box.
            && ! array_key_exists('auto_size', $body)
            && $body['texture'] === 'standard';
    });
});

it('sends one image when nobody has said which side is which', function (): void {
    realProvider();

    // An unlabelled catalogue still gets a model — the same one it would have got before any
    // of the labelling existed, through the single-image endpoint.
    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
        app(ProductViewTagger::class),
    );

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'image-to-3d')
        && ! str_contains($request->url(), 'multiview')
        && str_contains((string) $request->data()['image_url'], 'cover.jpg'));
});

/** Runs the job inline and returns it, outcome and all. */
function generate(?string $modelCode = null, ?string $keepAs = null): GenerateProductModel
{
    $job = new GenerateProductModel((string) test()->product->getKey(), $modelCode, $keepAs);

    $job->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        test()->models,
        app(ProductViewTagger::class),
    );

    return $job;
}

it('asks each generator family in its own shape', function (string $code, string $path, array $expected): void {
    realProvider(code: $code);

    generate();

    Http::assertSent(function (Request $request) use ($path, $expected): bool {
        if (! str_contains($request->url(), $path)) {
            return false;
        }

        $body = $request->data();

        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $body) || ($value !== null && $body[$field] !== $value)) {
                return false;
            }
        }

        return true;
    });
})->with([
    'Tripo H3.1' => ['tripo3d/h3.1/image-to-3d', 'tripo3d/h3.1/image-to-3d', ['image_url' => null, 'pbr' => true, 'face_limit' => 20_000]],
    'Rodin 2.5' => ['fal-ai/hyper3d/rodin/v2.5', 'fal-ai/hyper3d/rodin/v2.5', ['image_urls' => null, 'material' => 'PBR', 'geometry_file_format' => 'glb']],
    'Hunyuan3D v3' => ['fal-ai/hunyuan3d-v3/image-to-3d', 'fal-ai/hunyuan3d-v3/image-to-3d', ['input_image_url' => null, 'enable_pbr' => true, 'face_count' => 40_000]],
]);

it('reads the mesh from whichever field the generator puts it in', function (): void {
    realProvider(fakeHttp: false, code: 'fal-ai/hunyuan3d-v3/image-to-3d');

    // Hunyuan answers `model_glb`, not `model_mesh`.
    Http::fake([
        'rest.alpha.fal.ai/storage/upload/initiate*' => Http::response(['upload_url' => 'https://upload.fal.test/slot', 'file_url' => 'https://v3b.fal.test/p.jpeg']),
        'upload.fal.test/*' => Http::response('', 200),
        'fal.run/*' => Http::response(['model_glb' => ['url' => 'https://cdn.fal.test/mesh.glb']]),
        'cdn.fal.test/*' => Http::response(glb(), 200, ['Content-Type' => 'model/gltf-binary']),
    ]);

    expect(generate()->outcome['url'] ?? null)->not->toBeNull()
        ->and(ProductMedia::query()->where('product_id', $this->product->getKey())->where('type', 'model_3d')->exists())->toBeTrue();
});

it('keeps a bake-off result beside the catalogue rather than in it', function (): void {
    realProvider();

    // A second generator on the same provider, named by the job rather than by the route.
    $primary = AiTaskRoute::query()->where('task', 'product_model')->firstOrFail()->primaryModel;

    $primary?->replicate()->forceFill(['code' => 'fal-ai/hyper3d/rodin/v2.5', 'name' => 'Rodin'])->save();

    $job = generate('fal-ai/hyper3d/rodin/v2.5', 'rodin-2.5');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fal-ai/hyper3d/rodin/v2.5'));

    /*
     * The bake-off runs the same ten products through several generators and every result
     * has to survive the next one — so a candidate is a file in the bake-off folder, never
     * the product's model. Nothing in the shop or the planner sees it.
     */
    expect($job->outcome['url'] ?? null)->toContain('product-models/bakeoff/rodin-25/')
        ->and(Storage::disk('s3-public')->exists(ProductModelStorage::candidatePath($this->product, 'rodin-2.5')))->toBeTrue()
        ->and(ProductMedia::query()->where('product_id', $this->product->getKey())->where('type', 'model_3d')->exists())->toBeFalse();
});

it('puts the photograph on fal storage rather than sending a link it cannot reach', function (): void {
    realProvider();

    /*
     * The first real runs. A link to the bucket failed because the bucket was localhost; the
     * bytes as a data URI failed because the generator behind fal only downloads links. Both
     * answered "Failed to download the file". What works is fal's own storage: the bytes go
     * up, free, and the generator is given the CDN link that comes back.
     */
    Storage::disk('s3-public')->put('product-media/'.$this->product->getKey().'/cover.jpg', 'jpeg-bytes');

    (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
        app(ProductViewTagger::class),
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://upload.fal.test/slot'
        && $request->method() === 'PUT'
        && $request->body() === 'jpeg-bytes');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'image-to-3d')
        && $request->data()['image_url'] === 'https://v3b.fal.test/product.jpeg');

    // And a model came of it.
    expect(ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('type', 'model_3d')
        ->exists())->toBeTrue();
});

it('records nothing spent when fal refuses, and tries again once it would not', function (): void {
    realProvider(fakeHttp: false);

    // Thirty cents a model, the way the real route is priced.
    AiCostRate::query()->create([
        'model_id' => AiTaskRoute::query()->where('task', 'product_model')->firstOrFail()->primary_model_id,
        'currency' => 'TRY',
        'input_micros_per_million_tokens' => 0,
        'output_micros_per_million_tokens' => 0,
        'micros_per_image' => 0,
        'micros_per_request' => 300_000,
        'effective_from' => now()->subDay(),
    ]);

    /*
     * What actually happened: an account with no balance, answering 403 to every call. The
     * flat per-request fee used to be written for each refusal, so the ledger showed three
     * models bought — and that figure then told the idempotency check the jobs had been
     * billed and must never run again. The balance was topped up and nothing would retry.
     */
    Http::fake([
        'fal.run/*' => Http::sequence()
            ->push(['detail' => 'User is locked. Reason: TOP_UP.'], 403)
            ->push(['model_mesh' => ['url' => 'https://cdn.fal.test/mesh.glb']]),
        'cdn.fal.test/*' => Http::response(glb(), 200, ['Content-Type' => 'model/gltf-binary']),
    ]);

    $run = fn () => (new GenerateProductModel((string) $this->product->getKey()))->handle(
        app(AiJobDispatcher::class),
        app(GeneratedImageStore::class),
        $this->models,
        app(ProductViewTagger::class),
    );

    $run();

    $refused = AiJob::query()->where('task', 'product_model')->firstOrFail();

    expect($refused->total_cost_micros)->toBe(0);

    $run();

    expect(ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('type', 'model_3d')
        ->exists())->toBeTrue()
        // And the model that was made is the one that is paid for.
        ->and((int) AiJob::query()->where('task', 'product_model')->sum('total_cost_micros'))->toBe(300_000);
});

it('waits on the AI worker, which lets a generation take the minute it takes', function (): void {
    $job = new GenerateProductModel((string) $this->product->getKey());

    /*
     * On the default queue the worker killed anything past sixty seconds. Tripo takes about a
     * minute, so two of the first three real generations were killed after fal had made and
     * billed them, and before anything here recorded that they existed.
     */
    expect($job->queue)->toBe('ai')
        ->and($job->timeout)->toBeGreaterThan(180);
});

it('stores the mesh the optimiser hands back, and the raw one when it cannot', function (): void {
    config()->set('services.mesh_tools.url', 'http://mesh-tools:8080');

    /*
     * What a generator sends is a hundred thousand faces and a 4K texture; what a room with
     * ten products can afford is a fifth of that. The sidecar decimates and compresses it —
     * and when the sidecar is down, the raw mesh is stored rather than nothing, because a
     * heavy model is worse than a light one and better than no model at all.
     */
    Http::fake([
        'mesh-tools:8080/optimise*' => Http::sequence()
            ->push(glb('o'), 200, ['Content-Type' => 'model/gltf-binary', 'X-Triangles-Before' => '101440', 'X-Triangles-After' => '19980'])
            ->push(['error' => 'boom'], 500),
    ]);

    $optimised = $this->models->storeGenerated($this->product, glb('r'));

    expect(Storage::disk('s3-public')->get((string) $optimised->storage_path))->toBe(glb('o'));

    $raw = $this->models->storeGenerated($this->product, glb('r'));

    expect(Storage::disk('s3-public')->get((string) $raw->storage_path))->toBe(glb('r'));
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
        app(ProductViewTagger::class),
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
        app(ProductViewTagger::class),
    );

    // Nothing to convert, nothing spent, and the planner draws a box. Not an error.
    expect(FakeAiProvider::calls())->toBeEmpty()
        ->and(ProductMedia::query()->where('product_id', $this->product->getKey())->count())->toBe(0);
});

it('backfills only what is on sale: an archived listing is never sent for a model', function (): void {
    $archived = makeProduct(
        $this->seller,
        makeCategory('Sehpa', 'sehpa', 'living_room'),
        ['name' => 'Kaldırılmış sehpa', 'price_minor' => 40_000, 'width_mm' => 900],
    );
    $archived->forceFill(['status' => ProductStatus::Archived])->save();

    ProductMedia::query()->create([
        'product_id' => $archived->getKey(),
        'type' => 'image',
        'disk' => 's3-public',
        'storage_path' => 'product-media/'.$archived->getKey().'/cover.jpg',
        'original_name' => 'sehpa.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 90_000,
        'position' => 0,
    ]);

    // Priced without queueing anything: one product, the one that is still for sale.
    $this->artisan('refconcept:product-models', ['--dry-run' => true, '--limit' => 10])
        ->expectsOutputToContain('1 ürün')
        ->expectsOutputToContain('Üçlü kanepe')
        ->doesntExpectOutputToContain('Kaldırılmış sehpa')
        ->assertExitCode(0);
});

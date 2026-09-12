<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Providers\FakeAiProvider;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductViewTagger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Which side of a product each photograph shows.
 *
 * Worth knowing because the mesh generator charges the same for four views as for one and
 * stops inventing a back when it is given one. Worth being careful about because the answer
 * is written to the database, is then never asked again, and a photograph mislabelled as the
 * back makes the generator fuse two fronts into something that is not furniture.
 *
 * So everything here is about restraint: a seller's own labels are untouchable, an unsure
 * answer is dropped, a contradiction is dropped, and the simulator says nothing at all.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    FakeAiProvider::reset();
    Storage::fake('s3');
    Storage::fake('s3-public');

    makeAiRoute(AiTask::ProductViewTagging, ['credit_cost' => 0]);

    [$seller] = makeApprovedSeller('Yön Test A.Ş.', 'yon-test');

    $this->product = makeProduct(
        $seller,
        makeCategory('Kanepe', 'kanepe', 'living_room'),
        ['name' => 'Üçlü kanepe', 'price_minor' => 100_000, 'width_mm' => 2_200],
    );

    $this->tagger = app(ProductViewTagger::class);
});

afterEach(function (): void {
    FakeAiProvider::reset();
});

/** Four photographs of the same sofa, in gallery order and unlabelled. */
function photographs(int $count = 4): void
{
    for ($index = 0; $index < $count; $index++) {
        $path = 'product-media/'.test()->product->getKey().'/'.$index.'.jpg';

        // Real bytes on the fake disk: the gateway reads the file rather than fetching a URL.
        Storage::disk('s3-public')->put($path, 'jpeg-bytes-'.$index);

        ProductMedia::query()->create([
            'product_id' => test()->product->getKey(),
            'type' => 'image',
            'disk' => 's3-public',
            'storage_path' => $path,
            'original_name' => $index.'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 120_000,
            'position' => $index,
        ]);
    }
}

/**
 * Makes the next call answer with these view judgements.
 *
 * @param  array<int, array<string, mixed>>  $views
 */
function answerWith(array $views): void
{
    $structured = ['views' => $views];

    FakeAiProvider::script(AiResult::success(
        text: json_encode($structured, JSON_UNESCAPED_UNICODE) ?: '{}',
        structured: $structured,
        inputTokens: 100,
        outputTokens: 40,
    ));
}

/** @return array<string, string|null> view by gallery position */
function viewLabels(): array
{
    return ProductMedia::query()
        ->where('product_id', test()->product->getKey())
        ->where('type', 'image')
        ->orderBy('position')
        ->pluck('view', 'original_name')
        ->all();
}

it('labels each side from a confident answer', function (): void {
    photographs();

    answerWith([
        ['index' => 0, 'view' => 'front', 'confidence' => 0.95],
        ['index' => 1, 'view' => 'left', 'confidence' => 0.88],
        ['index' => 2, 'view' => 'back', 'confidence' => 0.91],
        ['index' => 3, 'view' => 'right', 'confidence' => 0.84],
    ]);

    expect($this->tagger->tag($this->product))->toBe(4)
        ->and(viewLabels())->toBe([
            '0.jpg' => 'front',
            '1.jpg' => 'left',
            '2.jpg' => 'back',
            '3.jpg' => 'right',
        ]);
});

it('leaves a photograph unlabelled rather than guess at it', function (): void {
    photographs(2);

    /*
     * A catalogue photograph of a sofa at three-quarters is neither the front nor the side,
     * and a model told to pick one will. Below the floor the answer is dropped: the generator
     * then invents a back, which is what it did before any of this existed and is a better
     * outcome than being handed the wrong one.
     */
    answerWith([
        ['index' => 0, 'view' => 'front', 'confidence' => 0.93],
        ['index' => 1, 'view' => 'back', 'confidence' => 0.42],
    ]);

    expect($this->tagger->tag($this->product))->toBe(1)
        ->and(viewLabels())->toBe(['0.jpg' => 'front', '1.jpg' => null]);
});

it('refuses anything that is not one of the four sides', function (): void {
    photographs(2);

    // The prompt asks for "other" when a photograph is a detail shot or a room scene. It is
    // the absence of a view rather than a view of its own, so nothing is written.
    answerWith([
        ['index' => 0, 'view' => 'other', 'confidence' => 0.99],
        ['index' => 1, 'view' => 'top', 'confidence' => 0.99],
    ]);

    expect($this->tagger->tag($this->product))->toBe(0)
        ->and(viewLabels())->toBe(['0.jpg' => null, '1.jpg' => null]);
});

it('keeps the first answer when the model names the same side twice', function (): void {
    photographs(3);

    /*
     * A model that names two backs has contradicted itself, and the database would refuse the
     * second write anyway — one of each side per product, by a partial unique index. Dropping
     * it here means a self-contradicting answer still labels what it got right.
     */
    answerWith([
        ['index' => 0, 'view' => 'front', 'confidence' => 0.9],
        ['index' => 1, 'view' => 'back', 'confidence' => 0.9],
        ['index' => 2, 'view' => 'back', 'confidence' => 0.9],
    ]);

    expect($this->tagger->tag($this->product))->toBe(2)
        ->and(viewLabels())->toBe(['0.jpg' => 'front', '1.jpg' => 'back', '2.jpg' => null]);
});

it('never overrules a seller who has already said', function (): void {
    photographs(2);

    // Somebody who photographed the thing knows which side they were standing on.
    ProductMedia::query()
        ->where('product_id', $this->product->getKey())
        ->where('original_name', '0.jpg')
        ->update(['view' => 'back']);

    expect($this->tagger->tag($this->product->fresh(['media'])))->toBe(0)
        // And nothing was spent asking a model to check a person's own answer.
        ->and(FakeAiProvider::calls())->toBeEmpty()
        ->and(viewLabels())->toBe(['0.jpg' => 'back', '1.jpg' => null]);
});

it('writes nothing when the simulator answers', function (): void {
    photographs(2);

    /*
     * With no key on file the task routes to the fake provider, and what it answers is an
     * empty list on purpose. A fabricated label would be written once and then block the real
     * answer forever, because a product with any label is never asked about again.
     */
    expect($this->tagger->tag($this->product))->toBe(0)
        ->and(viewLabels())->toBe(['0.jpg' => null, '1.jpg' => null]);
});

it('does nothing for a product with no photographs', function (): void {
    expect($this->tagger->tag($this->product))->toBe(0)
        ->and(FakeAiProvider::calls())->toBeEmpty();
});

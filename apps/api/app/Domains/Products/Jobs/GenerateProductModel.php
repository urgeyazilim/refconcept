<?php

declare(strict_types=1);

namespace App\Domains\Products\Jobs;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Services\ProductModelStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a 3D model of a product from its own photograph.
 *
 * Once per product, when a listing is approved, in the background. Nobody waits for it and
 * nothing breaks without it: a product with no mesh is drawn in the planner as a cut-out of
 * its photograph, which is what every product was drawn as before this existed.
 *
 * **No customer pays for this.** It is a catalogue cost, like the photograph itself — a few
 * tens of cents, once, for an asset every customer who ever plans that product into a room
 * then uses. Charging credits for it would be charging one customer to furnish the shop.
 *
 * **It never touches a seller's own model.** If they uploaded one from their manufacturer,
 * that is the real shape of the thing and this is a likeness; the storage refuses to let a
 * likeness stand in front of it.
 *
 * **It is not used in a render.** The mesh's far side is a guess — the photograph only shows
 * one — and a guess is fine in a planner seen across a room and not fine in the picture
 * somebody buys from.
 */
final class GenerateProductModel implements ShouldQueue
{
    use Queueable;

    /**
     * Once, and then leave it.
     *
     * A mesh that failed is a product without one, which is an ordinary state the planner
     * already handles. Retrying a paid generation three times because a provider was briefly
     * unhappy is paying three times for the same picture.
     */
    public int $tries = 1;

    public function __construct(public readonly string $productId) {}

    public function handle(
        AiJobDispatcher $dispatcher,
        GeneratedImageStore $files,
        ProductModelStorage $models,
    ): void {
        $product = Product::query()->with('media')->find($this->productId);

        if ($product === null) {
            return;
        }

        // A seller's own file outranks anything generated, so there is nothing to do.
        $existing = $models->bestFor($product);

        if ($existing !== null && $existing->source === 'seller') {
            return;
        }

        $photograph = $product->media->firstWhere('type', 'image');

        if ($photograph === null) {
            return;
        }

        try {
            $ran = $dispatcher->runInline(
                task: AiTask::ProductModel,
                input: [
                    'product_id' => (string) $product->getKey(),
                    /*
                     * A URL rather than bytes: this is the shop photograph, the one thing in
                     * this system that is already public, and the provider fetches it itself.
                     * Room photographs are never in reach of this task.
                     */
                    'image_urls' => [$photograph->url()],
                ],
                subject: $product,
                // Idempotent per photograph: a listing approved twice, or a worker that lost
                // its connection after the provider answered, does not pay twice.
                idempotencyKey: 'product-model:'.$photograph->getKey(),
                creditCostOverride: 0,
            );
        } catch (AiJobRefused $e) {
            // The task is paused or unrouted — an operator's decision, not a fault.
            Log::info('3B model üretimi atlandı.', ['product' => $this->productId, 'reason' => $e->getMessage()]);

            return;
        } catch (Throwable $e) {
            Log::warning('3B model üretimi başarısız.', ['product' => $this->productId, 'reason' => $e->getMessage()]);

            return;
        }

        if ($ran->status !== AiJobStatus::Succeeded) {
            Log::info('3B model üretilemedi.', [
                'product' => $this->productId,
                'reason' => $ran->failure_kind?->value,
            ]);

            return;
        }

        /** @var array<int, string> $refs */
        $refs = (array) ($ran->output['image_refs'] ?? []);

        $reference = $refs[0] ?? null;

        if (! is_string($reference) || ! $files->exists($reference)) {
            return;
        }

        $bytes = $files->read($reference);

        if (is_resource($bytes)) {
            $bytes = stream_get_contents($bytes);
        }

        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        $models->storeGenerated($product, $bytes);

        // Scratch space nobody empties becomes an archive of every mesh ever made.
        $files->discard($reference);
    }
}

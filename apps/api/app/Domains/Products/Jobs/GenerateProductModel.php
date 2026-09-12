<?php

declare(strict_types=1);

namespace App\Domains\Products\Jobs;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Ai\Services\GeneratedImageStore;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Products\Services\ProductModelStorage;
use App\Domains\Products\Services\ProductViewTagger;
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
        ProductViewTagger $viewTagger,
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

        /*
         * Which side of the product each photograph shows, if anybody knows.
         *
         * The generator charges the same for four views as for one and stops inventing a back
         * when it is given one, so this is the cheapest quality there is. A seller's own
         * labels are used as they are; otherwise a vision call has a look, and is allowed to
         * say it does not know.
         */
        $viewTagger->tag($product);

        $product->load('media');

        $labelled = $product->media
            ->where('type', 'image')
            ->whereNotNull('view')
            ->mapWithKeys(static fn (ProductMedia $media): array => [(string) $media->view => $media->url()])
            ->all();

        $photograph = $product->media->firstWhere('type', 'image');

        if ($photograph === null) {
            return;
        }

        /*
         * The front is whichever photograph is labelled as the front, or the first one.
         *
         * The generator requires a front and treats the other three as optional, so an
         * unlabelled catalogue still gets a model — the same one it would have got before any
         * of this existed.
         */
        $front = $labelled['front'] ?? $photograph->url();

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
                    'image_urls' => [$front],
                    // Front, left, back, right, as far as anybody knows them. The adapter
                    // sends the multi-view endpoint when there is more than a front.
                    'options' => ['views' => $labelled],
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

        /*
         * A simulator's answer is thrown away rather than stored.
         *
         * With no key on file the task routes to the fake provider, which succeeds — that is
         * its job — and returns a few bytes that begin the way a glTF binary begins. Storing
         * that would put a file that is not a model of anything on the public bucket for
         * every product ever approved, and the planner would try to load each one, fail, and
         * fall back to the photograph it should have used in the first place.
         *
         * Read from the request row rather than from configuration: what actually answered is
         * the only thing worth deciding on, and a route repointed by an operator at midday
         * would make any other answer stale.
         */
        $answeredBySimulator = $ran->requests()
            ->with('model.provider')
            ->latest('attempt')
            ->first()
            ?->model?->provider?->driver === 'fake';

        if ($answeredBySimulator) {
            Log::info('3B model simülatörden geldi; kaydedilmedi.', ['product' => $this->productId]);

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

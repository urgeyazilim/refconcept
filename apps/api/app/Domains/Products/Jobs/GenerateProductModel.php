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
use App\Domains\Products\Services\GlbInspector;
use App\Domains\Products\Services\ProductModelStorage;
use App\Domains\Products\Services\ProductViewTagger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Long enough for the generation it waits on, which is not short.
     *
     * The first real run put this on the default queue, whose worker kills anything past sixty
     * seconds. Tripo takes about a minute: one model squeezed in at sixty-one seconds and the
     * other two were killed mid-call — after fal had made them and billed for them, and before
     * anything here could write down that they existed.
     */
    public int $timeout = 600;

    /**
     * What happened, for a caller running this inline rather than on a worker.
     *
     * The bake-off command wants to know whether a generator answered, how big the answer
     * was, and how long it took — none of which a queued job has anybody to tell.
     *
     * @var array{url: string|null, bytes: int, triangles: int|null, textures: int, seconds: float, failure: string|null}|null
     */
    public ?array $outcome = null;

    /**
     * @param  string|null  $modelCode  a generator other than the one the route names — the bake-off, nothing else
     * @param  string|null  $keepAs  keep the result as a comparison candidate under this label rather than as the product's model
     */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $modelCode = null,
        public readonly ?string $keepAs = null,
    ) {
        // The AI worker: one process, a long timeout, and no payment callback waiting behind it.
        $this->onQueue('ai');
    }

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
            ->mapWithKeys(static fn (ProductMedia $media): array => [(string) $media->view => self::reachable($media)])
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
        $front = $labelled['front'] ?? self::reachable($photograph);

        $started = microtime(true);

        try {
            $ran = $dispatcher->runInline(
                task: AiTask::ProductModel,
                input: [
                    'product_id' => (string) $product->getKey(),
                    // Only the bake-off sets this, and only a job with no customer behind it
                    // is allowed to: the dispatcher strips it from anything a person queued.
                    ...($this->modelCode === null ? [] : ['model_override' => $this->modelCode]),
                    // The shop photograph, inline — see reachable(). Room photographs are never
                    // in reach of this task.
                    'image_urls' => [$front],
                    // Nothing for the gateway to read and inline: the adapter sends the image
                    // itself. Left out, the gateway falls back to fetching `image_urls` as if
                    // they were links, and logs a failure for every data URI it cannot GET.
                    'image_sources' => [],
                    // Front, left, back, right, as far as anybody knows them. The adapter
                    // sends the multi-view endpoint when there is more than a front.
                    'options' => ['views' => $labelled],
                ],
                subject: $product,
                // Idempotent per photograph: a listing approved twice, or a worker that lost
                // its connection after the provider answered, does not pay twice. Per
                // generator too, or the bake-off's second generator would be handed the
                // first one's answer.
                idempotencyKey: ($this->modelCode === null ? 'product-model:' : 'bakeoff:'.$this->modelCode.':').$photograph->getKey(),
                creditCostOverride: 0,
            );
        } catch (AiJobRefused $e) {
            // The task is paused or unrouted — an operator's decision, not a fault.
            Log::info('3B model üretimi atlandı.', ['product' => $this->productId, 'reason' => $e->getMessage()]);
            $this->outcome = self::failed($e->getMessage(), $started);

            return;
        } catch (Throwable $e) {
            Log::warning('3B model üretimi başarısız.', ['product' => $this->productId, 'reason' => $e->getMessage()]);
            $this->outcome = self::failed($e->getMessage(), $started);

            return;
        }

        if ($ran->status !== AiJobStatus::Succeeded) {
            Log::info('3B model üretilemedi.', [
                'product' => $this->productId,
                'reason' => $ran->failure_kind?->value,
            ]);
            $this->outcome = self::failed(($ran->failure_kind->value ?? 'failed').': '.(string) ($ran->failure_reason ?? ''), $started);

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
            $this->outcome = self::failed('Boş dosya.', $started);

            return;
        }

        $url = $this->keepAs === null
            ? $models->storeGenerated($product, $bytes)->url()
            : $models->storeCandidate($product, $bytes, $this->keepAs);

        $inspected = GlbInspector::inspect($bytes);

        $this->outcome = [
            'url' => $url,
            'bytes' => strlen($bytes),
            'triangles' => $inspected['triangles'] ?? null,
            'textures' => $inspected['textures'] ?? 0,
            'seconds' => round(microtime(true) - $started, 1),
            'failure' => null,
        ];

        // Scratch space nobody empties becomes an archive of every mesh ever made.
        $files->discard($reference);
    }

    /** @return array{url: null, bytes: int, triangles: null, textures: int, seconds: float, failure: string} */
    private static function failed(string $reason, float $started): array
    {
        return [
            'url' => null,
            'bytes' => 0,
            'triangles' => null,
            'textures' => 0,
            'seconds' => round(microtime(true) - $started, 1),
            'failure' => $reason,
        ];
    }

    /**
     * The photograph as something the provider can actually open.
     *
     * The bytes, as a data URI, rather than a link. A link was the first design — the shop
     * photograph is public, so why send more than its address — and it failed the first time
     * it met a real provider: in development the bucket is `localhost`, fal fetched it from
     * the internet, and three paid-for generations came back "Failed to download the file".
     * A link works only where the bucket happens to be reachable from outside; the bytes work
     * everywhere, and there is nothing in a product photograph to protect.
     *
     * Falls back to the link when the file cannot be read or is too large to inline, which is
     * no worse than what was sent before.
     */
    private static function reachable(ProductMedia $media): string
    {
        // Comfortably above the 8 MB upload ceiling; a file past this was not put there by it.
        $limit = 12 * 1024 * 1024;

        if ($media->size_bytes > $limit) {
            return $media->url();
        }

        try {
            $bytes = Storage::disk($media->disk)->get($media->storage_path);
        } catch (Throwable) {
            return $media->url();
        }

        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > $limit) {
            return $media->url();
        }

        return 'data:'.$media->mime_type.';base64,'.base64_encode($bytes);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Products\Console;

use App\Domains\Ai\Models\AiModel;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Jobs\GenerateProductModel;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Services\GlbInspector;
use App\Domains\Products\Services\ProductModelStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The same products through every generator, side by side, so the choice is made on our own
 * furniture rather than on a vendor's demo reel.
 *
 * Published comparisons rank these models on game props and characters. A bouclé sofa, a
 * round oak table and a wardrobe photographed on a white sweep are a different test, and the
 * only one that matters here. This runs a handful of real listings through each candidate,
 * keeps every result, and writes an index the comparison page reads.
 *
 * **Synchronous, and slow on purpose.** A generation is one to three minutes; ten products
 * through four generators is most of an hour. Run in the background, and read the log.
 *
 * **It says the price before it does anything**, from the routing table's own rates, and it
 * asks. Forty generations at forty cents is not a thing to start by accident.
 */
final class ModelBakeoffCommand extends Command
{
    protected $signature = 'refconcept:model-bakeoff
        {--limit=10 : How many products, spread across categories}
        {--models= : Comma-separated model codes; every fal 3D generator by default}
        {--products= : Comma-separated product ids or slugs, instead of choosing}
        {--dry-run : Price it and list it without generating anything}';

    protected $description = 'Aynı ürünleri her 3B üreticiden geçirip karşılaştırma dizini yazar.';

    /** Short names for the index and the folder, where the code would be a path. */
    private const LABELS = [
        'tripo3d/tripo/v2.5/image-to-3d' => 'tripo-2.5',
        'tripo3d/h3.1/image-to-3d' => 'tripo-h3.1',
        'fal-ai/hyper3d/rodin/v2.5' => 'rodin-2.5',
        'fal-ai/hunyuan3d-v3/image-to-3d' => 'hunyuan3d-v3',
    ];

    public function __construct(private readonly ProductModelStorage $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $models = $this->models();

        if ($models->isEmpty()) {
            $this->error('Karşılaştırılacak model yok. Önce AiGatewaySeeder çalıştırın ve FAL_API_KEY tanımlayın.');

            return self::FAILURE;
        }

        $products = $this->products();

        if ($products->isEmpty()) {
            $this->info('Fotoğraflı, onaylı ürün yok.');

            return self::SUCCESS;
        }

        $perProduct = $models->sum(fn (AiModel $model): int => (int) ($model->costRates->first()->micros_per_request ?? 0)) / 1_000_000;

        $this->line(sprintf(
            '%d ürün × %d model · ürün başına ~%.2f $ → toplam ~%.2f $',
            $products->count(),
            $models->count(),
            $perProduct,
            $products->count() * $perProduct,
        ));

        foreach ($models as $model) {
            $this->line(sprintf('  · %-32s %.2f $/model', $this->labelFor($model->code), ($model->costRates->first()->micros_per_request ?? 0) / 1_000_000));
        }

        foreach ($products as $product) {
            $this->line(sprintf('  · %s (%s)', $product->name, $product->primaryCategory->slug ?? '-'));
        }

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Devam edilsin mi?', false)) {
            $this->info('Vazgeçildi. Hiçbir şey üretilmedi.');

            return self::SUCCESS;
        }

        $results = [];

        foreach ($products as $product) {
            foreach ($models as $model) {
                $label = $this->labelFor($model->code);

                /*
                 * Already made in an earlier run: read the file rather than pay for it again.
                 * A run that died on the twelfth generation should resume at the twelfth,
                 * not start over at forty cents a step.
                 */
                $outcome = $this->kept($product, $label);

                if ($outcome === null) {
                    $job = new GenerateProductModel((string) $product->getKey(), $model->code, $label);

                    try {
                        app()->call([$job, 'handle']);
                        $outcome = $job->outcome;
                    } catch (Throwable $e) {
                        // One generator's tantrum must not end the run for the other three.
                        $outcome = ['url' => null, 'bytes' => 0, 'triangles' => null, 'textures' => 0, 'seconds' => 0.0, 'failure' => $e->getMessage()];
                    }
                }

                $results[(string) $product->getKey()][$label] = $outcome;

                $this->line(sprintf(
                    '%s · %s → %s',
                    Str::limit($product->name, 32),
                    $label,
                    $outcome === null || $outcome['failure'] !== null
                        ? 'HATA '.($outcome['failure'] ?? 'sonuç yok')
                        : sprintf('%.1f sn · %s üçgen · %d doku · %.1f MB', $outcome['seconds'], number_format((int) $outcome['triangles']), $outcome['textures'], $outcome['bytes'] / 1_048_576),
                ));

                // Written after every result, so a run stopped halfway still has a page.
                $url = $this->storage->storeCandidateIndex($this->index($products, $models, $results));
            }
        }

        $this->newLine();
        $this->info('Dizin: '.($url ?? '-'));
        $this->info('Karşılaştırma sayfası: http://localhost:3000/lab/model-bakeoff?index='.urlencode($url ?? ''));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function models(): Collection
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));

        return AiModel::query()
            ->with(['costRates', 'provider.credentials'])
            ->where('modality', 'model_3d')
            ->where('is_active', true)
            ->when($codes !== [], fn (Builder $query) => $query->whereIn('code', $codes))
            ->get()
            // Only what can actually be called: the simulator has no key and no opinion.
            ->filter(fn (AiModel $model): bool => $model->provider?->driver !== 'fake' && $model->provider?->activeCredential() !== null)
            ->values();
    }

    /**
     * Ten products spread across categories, or exactly the ones asked for.
     *
     * Spread because a bake-off of ten sofas says nothing about tables. One from each
     * category first, round robin, until the limit.
     *
     * @return Collection<int, Product>
     */
    private function products(): Collection
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = Product::query()
            ->with(['primaryCategory', 'media', 'skus.dimensions'])
            ->where('moderation_status', ModerationStatus::Approved)
            ->whereHas('media', fn (Builder $media) => $media->where('type', 'image'));

        $chosen = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('products')))));

        if ($chosen !== []) {
            return $query
                ->where(fn (Builder $q) => $q->whereIn('id', $chosen)->orWhereIn('slug', $chosen))
                ->get();
        }

        $byCategory = $query->orderBy('created_at')->get()->groupBy('primary_category_id')->values();

        /** @var Collection<int, Product> $picked */
        $picked = new Collection;

        for ($round = 0; $picked->count() < $limit; $round++) {
            $added = false;

            foreach ($byCategory as $group) {
                $product = $group->get($round);

                if ($product !== null && $picked->count() < $limit) {
                    $picked->push($product);
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $picked;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, AiModel>  $models
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function index(Collection $products, Collection $models, array $results): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'models' => $models->map(fn (AiModel $model): array => [
                'code' => $model->code,
                'label' => $this->labelFor($model->code),
                'name' => $model->name,
                'price_usd' => ($model->costRates->first()->micros_per_request ?? 0) / 1_000_000,
            ])->values()->all(),
            'products' => $products->map(function (Product $product): array {
                $sku = $product->skus->first(fn ($sku) => ($sku->dimensions->width_mm ?? 0) > 0) ?? $product->skus->first();
                $cover = $product->media->where('type', 'image')->sortBy('position')->first();

                return [
                    'id' => (string) $product->getKey(),
                    'name' => (string) $product->name,
                    'category' => $product->primaryCategory?->slug,
                    'width_mm' => $sku->dimensions->width_mm ?? null,
                    'height_mm' => $sku->dimensions->height_mm ?? null,
                    'depth_mm' => $sku->dimensions->depth_mm ?? null,
                    'image_url' => $cover?->url(),
                ];
            })->values()->all(),
            'results' => $results,
        ];
    }

    private function labelFor(string $code): string
    {
        return self::LABELS[$code] ?? Str::slug($code);
    }

    /**
     * A candidate an earlier run already stored, inspected from the file.
     *
     * @return array<string, mixed>|null
     */
    private function kept(Product $product, string $label): ?array
    {
        $disk = Storage::disk((string) config('refconcept.storage.public_disk', config('filesystems.default')));
        $path = ProductModelStorage::candidatePath($product, $label);

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $inspected = GlbInspector::inspect($bytes);

        return [
            'url' => $disk->url($path),
            'bytes' => strlen($bytes),
            'triangles' => $inspected['triangles'] ?? null,
            'textures' => $inspected['textures'] ?? 0,
            'seconds' => 0.0,
            'failure' => null,
            'kept' => true,
        ];
    }
}

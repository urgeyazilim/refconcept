<?php

declare(strict_types=1);

namespace App\Domains\Products\Console;

use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Products\Enums\ModerationStatus;
use App\Domains\Products\Jobs\GenerateProductModel;
use App\Domains\Products\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Converts a catalogue that already exists into 3D models, once.
 *
 * New listings get a model when they are approved. Everything approved before the feature
 * existed does not, and this is how those are caught up — a backfill somebody runs
 * deliberately, in a terminal, having read what it will cost.
 *
 * **It says the price before it does anything.** Three hundred products is ninety dollars,
 * and a command that quietly spends that because somebody typed it while exploring is a
 * command that should not exist. The count and the figure come from the routing table's own
 * per-request rate, so they cannot drift from what will actually be charged.
 *
 * **It queues rather than runs.** One at a time on the AI queue, in the background: a
 * catalogue import that approves four hundred listings should trickle rather than open four
 * hundred connections to a provider in a minute.
 */
final class GenerateProductModelsCommand extends Command
{
    protected $signature = 'refconcept:product-models
        {--limit=50 : How many products to convert in this run}
        {--force : Include products that already have a generated model}
        {--dry-run : Count and price the work without queueing any of it}';

    protected $description = 'Yayındaki ürünler için fotoğraflarından 3B model üretir.';

    public function handle(): int
    {
        $route = AiTaskRoute::query()->where('task', 'product_model')->first();

        if ($route === null) {
            $this->error('product_model görevi için rota yok. Önce AiGatewaySeeder çalıştırın.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $products = $this->candidates((bool) $this->option('force'))->limit($limit)->get();

        if ($products->isEmpty()) {
            $this->info('Modeli üretilecek ürün yok.');

            return self::SUCCESS;
        }

        /*
         * Both figures, from the tables rather than from a number typed here.
         *
         * The expected price is the model's own per-request rate; the ceiling is what the
         * gateway will refuse to exceed. Quoting only the first understates a bill somebody
         * is about to authorise, and quoting only the second overstates it by double — and a
         * constant written into this help text would drift away from both.
         */
        $route->loadMissing(['primaryModel.costRates', 'primaryModel.provider']);

        /*
         * Said first, because it changes what everything below means.
         *
         * With no key on file the task is routed to the simulator: the command will run, the
         * queue will fill, every product will get a file that is not a model of anything, and
         * nothing will be charged. Somebody backfilling a catalogue needs to know that before
         * they watch ninety jobs succeed and wonder why the planner looks the same.
         */
        if ($route->primaryModel?->provider?->driver === 'fake') {
            $this->warn('Bu görev şu an simülatöre yönlü — gerçek model üretilmez, ücret çıkmaz.');
            $this->warn('Gerçek üretim için FAL_API_KEY tanımlayıp AiGatewaySeeder çalıştırın.');
        }

        $rate = $route->primaryModel?->costRates->first();

        $expected = ((int) ($rate === null ? 0 : $rate->micros_per_request)) / 1_000_000;
        $ceiling = ((int) ($route->max_cost_micros ?? 0)) / 1_000_000;

        $this->line(sprintf(
            '%d ürün · beklenen %.2f $/model → toplam ~%.2f $ (üst sınır %.2f $).',
            $products->count(),
            $expected,
            $products->count() * $expected,
            $products->count() * $ceiling,
        ));

        if ((bool) $this->option('dry-run')) {
            foreach ($products as $product) {
                $this->line('  · '.$product->name);
            }

            return self::SUCCESS;
        }

        if (! $this->confirm('Devam edilsin mi?', false)) {
            $this->info('Vazgeçildi. Hiçbir şey kuyruğa alınmadı.');

            return self::SUCCESS;
        }

        foreach ($products as $product) {
            GenerateProductModel::dispatch((string) $product->getKey());
        }

        $this->info(sprintf('%d ürün kuyruğa alındı.', $products->count()));

        return self::SUCCESS;
    }

    /**
     * @return Builder<Product>
     */
    private function candidates(bool $force): Builder
    {
        return Product::query()
            // Approved only. A listing nobody has accepted yet may never be sold, and a mesh
            // made for one is thirty cents spent on a product that does not exist.
            ->where('moderation_status', ModerationStatus::Approved)
            ->whereHas('media', fn (Builder $media) => $media->where('type', 'image'))
            ->when(! $force, fn (Builder $query) => $query->whereDoesntHave(
                'media',
                fn (Builder $media) => $media->where('type', 'model_3d'),
            ))
            ->orderBy('created_at');
    }
}

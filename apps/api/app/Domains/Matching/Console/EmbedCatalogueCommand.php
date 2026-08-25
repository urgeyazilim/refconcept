<?php

declare(strict_types=1);

namespace App\Domains\Matching\Console;

use App\Domains\Matching\Services\ProductEmbedder;
use App\Domains\Products\Models\Product;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gives every listable product a vector.
 *
 * Run after a catalogue import, and nightly to catch what changed. Only products a
 * customer can actually be shown are embedded: spending a provider call on a draft listing
 * buys a vector for something that cannot appear in a result.
 *
 * Reports three numbers rather than one. "1200 processed" hides whether anything happened;
 * "18 embedded, 1182 unchanged, 0 failed" is the sentence somebody can act on — and the
 * middle number being large is the point of hashing the input.
 */
final class EmbedCatalogueCommand extends Command
{
    protected $signature = 'refconcept:embed-catalogue
                            {--force : Re-embed even when the text has not changed}
                            {--chunk=100 : How many products to load at a time}';

    protected $description = 'Generate search vectors for every publicly visible product';

    public function handle(ProductEmbedder $embedder): int
    {
        $force = (bool) $this->option('force');

        $embedded = 0;
        $unchanged = 0;
        $failed = 0;

        $embedder->pending()
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($products) use ($embedder, $force, &$embedded, &$unchanged, &$failed): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    try {
                        $result = $embedder->embed($product, $force);

                        $result === null ? $unchanged++ : $embedded++;
                    } catch (Throwable $e) {
                        /*
                         * One product's failure does not stop the pass. A provider that
                         * rate-limits halfway through a catalogue would otherwise leave the
                         * second half unsearchable, and the run that fixes it would start
                         * from the beginning.
                         */
                        $failed++;

                        $this->warn(sprintf('%s: %s', $product->slug, $e->getMessage()));
                    }
                }
            });

        $this->info(sprintf(
            '%d ürün vektörlendi, %d değişmemişti, %d başarısız.',
            $embedded,
            $unchanged,
            $failed,
        ));

        // A non-zero exit when anything failed, so a scheduled run is visibly wrong rather
        // than quietly incomplete.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

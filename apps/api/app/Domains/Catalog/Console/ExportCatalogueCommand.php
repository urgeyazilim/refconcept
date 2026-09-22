<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console;

use App\Domains\Matching\Models\ProductEmbedding;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the catalogue out as something another environment can read back.
 *
 * A deploy ships code. It does not ship the catalogue, and the first thing anybody notices
 * on a fresh server is "ürün bulunamadı" — the seeders that run in production are reference
 * data, so the shop is a working shop with nothing in it.
 *
 * Most of what is missing can be seeded: {@see \Database\Seeders\DemoCatalogSeeder} creates
 * the listings and ships their photographs in the repository. Two things cannot. The 3D
 * models were made by a paid generator, one call per product, and the search vectors by a
 * paid embedding call; regenerating either on a new server is spending the same money twice
 * for the same answer. So they travel in a file instead.
 *
 * **Keyed by slug, never by id.** The seeder is idempotent by slug and mints fresh UUIDs on
 * an empty database, so the product that is `arden-boucle-uclu-kanepe` here is a different
 * id there. An export keyed by id restores nothing and says it worked.
 *
 * Writes a directory rather than one file: a hundred and nine meshes inside a JSON document
 * is a JSON document nobody can open, and base64 makes it a third larger for the privilege.
 */
final class ExportCatalogueCommand extends Command
{
    protected $signature = 'refconcept:export-catalogue
        {--out= : Where to write; defaults to storage/app/catalogue-export}
        {--all : Every product, not only the ones on sale}
        {--only= : Only these media types, comma separated — e.g. model_3d}';

    protected $description = 'Ürünlerin 3B modellerini ve arama vektörlerini başka bir ortama taşınabilir biçimde yazar.';

    public function handle(): int
    {
        $out = (string) ($this->option('out') ?: storage_path('app/catalogue-export'));

        File::ensureDirectoryExists($out.'/files');

        $products = Product::query()
            ->when(! $this->option('all'), fn ($query) => $query->where('status', 'active'))
            ->orderBy('slug')
            ->get(['id', 'slug', 'name']);

        if ($products->isEmpty()) {
            $this->error('Dışa aktarılacak ürün yok.');

            return self::FAILURE;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));

        $manifest = [];
        $files = 0;
        $vectors = 0;

        foreach ($products as $product) {
            $entry = ['slug' => $product->slug, 'name' => $product->name, 'media' => [], 'embeddings' => []];

            /*
             * Everything by default, and `--only=model_3d` for the case this exists for.
             *
             * The repository already ships the demo photographs beside the seeder that makes
             * the listings, so an export carrying them too is two and a half megabytes of the
             * same pictures. The meshes are the part no seeder can provide: each one is a paid
             * generation, and making them again on a new server is paying twice for the same
             * answer.
             *
             * Everything remains the default, because a catalogue worked on since the seeder
             * was written has photographs the seeder never had, and telling those apart from
             * out here is not something this command can do honestly.
             */
            foreach (ProductMedia::query()->where('product_id', $product->getKey())->when($only !== [], fn ($query) => $query->whereIn('type', $only))->orderBy('position')->get() as $media) {
                $bytes = $this->read($media);

                if ($bytes === null) {
                    $this->warn(sprintf('  %s: %s dosyası diskte yok, atlandı.', $product->slug, $media->storage_path));

                    continue;
                }

                $name = hash('sha256', $bytes).'.'.pathinfo($media->storage_path, PATHINFO_EXTENSION);

                File::put($out.'/files/'.$name, $bytes);
                $files++;

                $entry['media'][] = [
                    'file' => $name,
                    'type' => $media->type,
                    'disk' => $media->disk,
                    'original_name' => $media->original_name,
                    'mime_type' => $media->mime_type,
                    'size_bytes' => $media->size_bytes,
                    'width' => $media->width,
                    'height' => $media->height,
                    'alt_text' => $media->alt_text,
                    'position' => $media->position,
                    'source' => $media->source,
                    'view' => $media->view,
                ];
            }

            // The vector itself, as the string pgvector both prints and parses.
            foreach (ProductEmbedding::query()->where('product_id', $product->getKey())->get() as $embedding) {
                $entry['embeddings'][] = [
                    'source' => $embedding->source,
                    'model' => $embedding->model,
                    'content_hash' => $embedding->content_hash,
                    'embedding' => (string) $embedding->getRawOriginal('embedding'),
                ];

                $vectors++;
            }

            $manifest[] = $entry;
        }

        File::put($out.'/catalogue.json', json_encode([
            'exported_at' => now()->toIso8601String(),
            'products' => $manifest,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            '%d ürün, %d dosya, %d vektör → %s',
            count($manifest),
            $files,
            $vectors,
            $out,
        ));

        return self::SUCCESS;
    }

    /** The bytes behind a media row, or null when the row outlived its file. */
    private function read(ProductMedia $media): ?string
    {
        $disk = Storage::disk($media->disk);

        return $disk->exists($media->storage_path) ? $disk->get($media->storage_path) : null;
    }
}

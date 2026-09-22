<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Console;

use App\Domains\Matching\Models\ProductEmbedding;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads a catalogue export back, onto a server that has the listings but not their files.
 *
 * The other half of {@see ExportCatalogueCommand}. Run the demo catalogue seeder first so
 * the products exist, then this: it finds each product by slug and gives it the 3D model and
 * the search vector that were made on the machine the export came from.
 *
 * **Matched by slug.** The seeder mints fresh UUIDs on an empty database, so the ids in the
 * export belong to another server and mean nothing here. A product named in the export and
 * missing here is reported rather than created — a catalogue import that invents listings is
 * a catalogue import nobody can check.
 *
 * **Skips what is already there.** Media is matched on its type and its original name, so
 * running this twice does not give a sofa two identical meshes. Vectors are matched on their
 * content hash, which is what decides whether one needs making again anyway.
 *
 * Safe to run on a server with customers: it adds files to products and touches nothing a
 * customer owns. It is still an import, so it says what it did rather than finishing quietly.
 */
final class ImportCatalogueCommand extends Command
{
    protected $signature = 'refconcept:import-catalogue
        {dir : The directory a catalogue export was written to}
        {--pretend : Say what would happen and write nothing}';

    protected $description = 'Dışa aktarılmış 3B modelleri ve arama vektörlerini bu ortama yükler.';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $manifest = $dir.'/catalogue.json';

        if (! File::exists($manifest)) {
            $this->error($manifest.' bulunamadı.');

            return self::FAILURE;
        }

        /** @var array{products?: array<int, array<string, mixed>>} $payload */
        $payload = json_decode((string) File::get($manifest), true) ?: [];
        $products = (array) ($payload['products'] ?? []);

        if ($products === []) {
            $this->error('Dosyada ürün yok.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $added = 0;
        $vectors = 0;
        $missing = [];

        foreach ($products as $entry) {
            $slug = (string) ($entry['slug'] ?? '');
            $product = $slug === '' ? null : Product::query()->where('slug', $slug)->first();

            if ($product === null) {
                $missing[] = $slug;

                continue;
            }

            foreach ((array) ($entry['media'] ?? []) as $media) {
                if ($this->carry($product, $dir, (array) $media, $pretend)) {
                    $added++;
                }
            }

            foreach ((array) ($entry['embeddings'] ?? []) as $embedding) {
                if ($this->remember($product, (array) $embedding, $pretend)) {
                    $vectors++;
                }
            }
        }

        $this->info(sprintf(
            '%s%d dosya, %d vektör eklendi.',
            $pretend ? '[deneme] ' : '',
            $added,
            $vectors,
        ));

        if ($missing !== []) {
            /*
             * Named, not counted.
             *
             * "Sekiz ürün bulunamadı" is a number somebody has to go and investigate; the
             * slugs are the investigation. Usually it means the seeder has not been run here
             * yet, and occasionally it means a product was renamed on one side only.
             */
            $this->warn(sprintf('Bu ürünler burada yok, atlandı (%d): %s', count($missing), implode(', ', array_slice($missing, 0, 12))));
        }

        return self::SUCCESS;
    }

    /**
     * One file onto the product, unless it already has one like it.
     *
     * @param  array<string, mixed>  $media
     */
    private function carry(Product $product, string $dir, array $media, bool $pretend): bool
    {
        $name = (string) ($media['file'] ?? '');
        $path = $dir.'/files/'.$name;

        if ($name === '' || ! File::exists($path)) {
            $this->warn(sprintf('  %s: %s dosyası pakette yok.', $product->slug, $name));

            return false;
        }

        $already = ProductMedia::query()
            ->where('product_id', $product->getKey())
            ->where('type', $media['type'] ?? '')
            ->where('original_name', $media['original_name'] ?? '')
            ->exists();

        if ($already) {
            return false;
        }

        if ($pretend) {
            return true;
        }

        $disk = (string) ($media['disk'] ?? 's3-public');
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $storagePath = sprintf('product-media/%s/%s.%s', $product->getKey(), Str::uuid7()->toString(), $extension);

        Storage::disk($disk)->put($storagePath, File::get($path));

        ProductMedia::query()->create([
            'product_id' => $product->getKey(),
            'type' => $media['type'] ?? 'image',
            'disk' => $disk,
            'storage_path' => $storagePath,
            'original_name' => $media['original_name'] ?? $name,
            'mime_type' => $media['mime_type'] ?? null,
            'size_bytes' => $media['size_bytes'] ?? File::size($path),
            'width' => $media['width'] ?? null,
            'height' => $media['height'] ?? null,
            'alt_text' => $media['alt_text'] ?? null,
            'position' => $media['position'] ?? 0,
            'source' => $media['source'] ?? null,
            'view' => $media['view'] ?? null,
        ]);

        return true;
    }

    /**
     * One vector onto the product, unless the same content has one already.
     *
     * @param  array<string, mixed>  $embedding
     */
    private function remember(Product $product, array $embedding, bool $pretend): bool
    {
        $hash = (string) ($embedding['content_hash'] ?? '');
        $vector = (string) ($embedding['embedding'] ?? '');

        if ($hash === '' || $vector === '') {
            return false;
        }

        $already = ProductEmbedding::query()
            ->where('product_id', $product->getKey())
            ->where('content_hash', $hash)
            ->exists();

        if ($already || $pretend) {
            return ! $already;
        }

        /*
         * Written as SQL because pgvector's type is not one Eloquent knows how to bind: the
         * column takes its own literal, and an array cast on the way in produces a string
         * Postgres reads as text and refuses.
         */
        DB::statement(
            'insert into product_embeddings (id, product_id, source, model, content_hash, embedding, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?::vector, now(), now())',
            [
                (string) Str::uuid7(),
                $product->getKey(),
                $embedding['source'] ?? 'catalogue',
                $embedding['model'] ?? 'imported',
                $hash,
                $vector,
            ],
        );

        return true;
    }
}

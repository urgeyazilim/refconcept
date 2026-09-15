<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a product's 3D model lives.
 *
 * The public product bucket, beside the photographs, for the same reason and with the same
 * caching: a mesh is a shop asset, the object key carries a UUID so a URL always names the
 * same bytes, and the browser may hold it for a year.
 *
 * One model per source per product, enforced by a partial unique index rather than by care.
 * A seller's own file and a mesh generated from their photograph are different things with
 * different standing — the seller's is authoritative, the generated one is a likeness whose
 * back is a guess — and the planner has to be able to tell them apart to label one and prefer
 * the other.
 *
 * glTF binary only. It is the one format every browser engine can load without a converter,
 * it carries its textures inside a single file, and a planner that accepts four formats is a
 * planner that fails on a customer's screen in the fourth one.
 */
final class ProductModelStorage
{
    /** What a bake-off candidate may weigh. See storeCandidate(). */
    public const MAX_CANDIDATE_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly MeshOptimiser $optimiser) {}

    /**
     * What a seller may upload.
     *
     * `model/gltf-binary` is the registered type; browsers and operating systems disagree
     * about what they send for a `.glb`, so the extension is checked as well and an empty
     * or generic type from the browser is not by itself a refusal.
     */
    public const ALLOWED_MIME_TYPES = [
        'model/gltf-binary',
        'application/octet-stream',
        '',
    ];

    /**
     * Twenty megabytes.
     *
     * A furniture mesh with a texture is one to five; twenty leaves room for a detailed one
     * and refuses the export somebody made without decimating, which would be a hundred and
     * would stall a phone rather than fail on it.
     */
    public const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    /** A seller's own file, which always outranks anything generated. */
    public function storeUpload(Product $product, UploadedFile $file, User $uploader): ProductMedia
    {
        $this->assertAcceptable($file);

        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            throw new RuntimeException('Yüklenen model okunamadı.');
        }

        return $this->put($product, $this->optimiser->optimise($bytes)['bytes'], 'seller', $file->getClientOriginalName(), $uploader->getKey());
    }

    /**
     * A mesh made from the product's own photograph.
     *
     * Passed through the optimiser first, like an upload: what a generator sends is a hundred
     * thousand faces and a 4K texture, and what a room with ten products in it can afford is
     * a fifth of that. Stored raw if the optimiser is unreachable — heavier, still a model.
     */
    public function storeGenerated(Product $product, string $bytes): ProductMedia
    {
        return $this->put($product, $this->optimiser->optimise($bytes)['bytes'], 'ai', 'uretilen-model.glb', null);
    }

    /**
     * Whichever model the planner should use: the seller's, or the generated one.
     *
     * A seller who has a file from the manufacturer has the real shape of the thing, and no
     * generated likeness should ever stand in front of it.
     */
    public function bestFor(Product $product): ?ProductMedia
    {
        return ProductMedia::query()
            ->where('product_id', $product->getKey())
            ->where('type', 'model_3d')
            ->orderByRaw("CASE WHEN source = 'seller' THEN 0 ELSE 1 END")
            ->first();
    }

    /**
     * Removes the generated mesh, leaving anything the seller uploaded alone.
     *
     * What an operator does with a likeness that came out wrong — a sofa with three arms is
     * worse than no model at all, and the planner falls back to the photograph cut-out.
     */
    public function discardGenerated(Product $product): void
    {
        ProductMedia::query()
            ->where('product_id', $product->getKey())
            ->where('type', 'model_3d')
            ->where('source', 'ai')
            ->get()
            ->each(function (ProductMedia $media): void {
                Storage::disk($media->disk)->delete($media->storage_path);
                $media->delete();
            });
    }

    /**
     * A mesh kept for comparison rather than for the catalogue.
     *
     * The bake-off runs the same ten products through several generators, and each result
     * has to survive the next one. These are files and not media rows: nothing in the shop or
     * the planner ever sees them, the one-per-source index does not apply, and throwing the
     * lot away afterwards is deleting a folder.
     *
     * @return string the file's public URL
     */
    public function storeCandidate(Product $product, string $bytes, string $label): string
    {
        // Looser than the catalogue's limit on purpose: the point is to see what a generator
        // sends, and Rodin's first answer was 26 MB of geometry and PNG. Nothing here is
        // served to a customer.
        if (strlen($bytes) > self::MAX_CANDIDATE_BYTES) {
            throw new RuntimeException('Aday model 64 MB sınırını aşıyor.');
        }

        $path = self::candidatePath($product, $label);

        Storage::disk($this->disk())->put($path, $bytes, [
            'visibility' => 'public',
            'ContentType' => 'model/gltf-binary',
        ]);

        return Storage::disk($this->disk())->url($path);
    }

    /** Where a candidate goes: one folder per generator, one file per product. */
    public static function candidatePath(Product $product, string $label): string
    {
        return sprintf('product-models/bakeoff/%s/%s.glb', Str::slug($label), $product->getKey());
    }

    /**
     * Writes the bake-off's own index, the file the comparison page reads.
     *
     * @param  array<string, mixed>  $index
     * @return string the index's public URL
     */
    public function storeCandidateIndex(array $index): string
    {
        $path = 'product-models/bakeoff/index.json';

        Storage::disk($this->disk())->put($path, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', [
            'visibility' => 'public',
            'ContentType' => 'application/json',
            'CacheControl' => 'no-cache',
        ]);

        return Storage::disk($this->disk())->url($path);
    }

    // --- internals -------------------------------------------------------------

    private function put(
        Product $product,
        string $bytes,
        string $source,
        string $originalName,
        ?string $uploadedBy,
    ): ProductMedia {
        if (strlen($bytes) > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('3B model 20 MB sınırını aşıyor.');
        }

        // Replaced rather than added to: the index allows one per source, and a product
        // whose photograph changed should get the mesh made from the new one.
        $this->forget($product, $source);

        $disk = $this->disk();

        $path = sprintf('product-models/%s/%s.glb', $product->getKey(), Str::uuid7()->toString());

        Storage::disk($disk)->put($path, $bytes, [
            'visibility' => 'public',
            // Immutable for the same reason the photographs are: the key contains a UUID, so
            // these bytes are never replaced — only superseded by a row with a new key.
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => 'model/gltf-binary',
        ]);

        return ProductMedia::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'model_3d',
            'source' => $source,
            'disk' => $disk,
            'storage_path' => $path,
            'original_name' => Str::limit($originalName, 250, ''),
            'mime_type' => 'model/gltf-binary',
            'size_bytes' => strlen($bytes),
            // Deliberately last: a model is not a picture in the gallery, and position 0
            // belongs to the cover photograph.
            'position' => 99,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    private function forget(Product $product, string $source): void
    {
        ProductMedia::query()
            ->where('product_id', $product->getKey())
            ->where('type', 'model_3d')
            ->where('source', $source)
            ->get()
            ->each(function (ProductMedia $media): void {
                Storage::disk($media->disk)->delete($media->storage_path);
                $media->forceDelete();
            });
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('3B model 20 MB sınırını aşıyor.');
        }

        $extension = Str::lower($file->getClientOriginalExtension());

        if ($extension !== 'glb') {
            throw new RuntimeException('Yalnızca .glb dosyası yüklenebilir.');
        }

        /*
         * The magic number, not the name.
         *
         * A file called sofa.glb containing anything at all would otherwise be served from a
         * bucket we have made anonymously readable. glTF binary starts with "glTF".
         */
        $handle = fopen($file->getRealPath(), 'rb');

        $magic = $handle === false ? '' : (string) fread($handle, 4);

        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic !== 'glTF') {
            throw new RuntimeException('Dosya geçerli bir glTF binary değil.');
        }
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.public_disk', config('filesystems.default'));
    }
}

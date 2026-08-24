<?php

declare(strict_types=1);

namespace App\Domains\Products\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductMedia;
use App\Domains\Sellers\Services\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores product imagery.
 *
 * The mirror image of {@see DocumentStorage}: these
 * files are *meant* for the open web, so they go to the public bucket and are served
 * by URL rather than by signed link. Everything else stays the same — the object key
 * is random, the MIME type is checked against the decoded bytes, and the writer is
 * this class alone.
 *
 * Position is the ordering contract: 0 is the cover, and a partial unique index in
 * the database enforces that there is exactly one of them per product. Reordering
 * therefore has to go through a transaction that moves every row, not a single
 * UPDATE — see {@see reorder()}.
 */
final class ProductImageStorage
{
    /** Formats a browser renders natively. No TIFF, no HEIC, no SVG. */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * SVG is deliberately absent above: it is a document format that can carry script,
     * and it would be served from a bucket we have made anonymously readable.
     */
    public const MAX_SIZE_BYTES = 8 * 1024 * 1024;

    public const MAX_PER_PRODUCT = 12;

    public function store(
        Product $product,
        UploadedFile $file,
        User $uploader,
        ?string $altText = null,
    ): ProductMedia {
        $this->assertAcceptable($product, $file);

        $disk = $this->disk();
        $extension = $this->extensionFor($file);

        $path = sprintf(
            'product-media/%s/%s.%s',
            $product->getKey(),
            Str::uuid7()->toString(),
            $extension,
        );

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Yüklenen görsel okunamadı.');
        }

        try {
            Storage::disk($disk)->put($path, $stream, 'public');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Dimensions are read once here rather than on every render: a catalogue grid
        // that knows the aspect ratio up front does not reflow as images arrive.
        [$width, $height] = $this->dimensions($file);

        return ProductMedia::query()->create([
            'product_id' => $product->getKey(),
            'type' => 'image',
            'disk' => $disk,
            'storage_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
            'position' => $this->nextPosition($product),
            'uploaded_by' => $uploader->getKey(),
        ]);
    }

    /**
     * Rewrites the whole ordering from a list of ids.
     *
     * Two things make this fiddlier than it looks. The single-cover index rejects any
     * intermediate state with two rows at position 0, so the rows are first parked at
     * negative positions the index cannot collide on. And ids that do not belong to
     * this product are dropped rather than trusted, because the list arrives from the
     * browser.
     *
     * @param  array<int, string>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds): void
    {
        DB::transaction(function () use ($product, $orderedIds): void {
            /** @var array<int, string> $owned */
            $owned = $product->media()->pluck('id')->all();

            $ordered = array_values(array_filter(
                $orderedIds,
                static fn (string $id): bool => in_array($id, $owned, true),
            ));

            // Anything the client forgot to mention keeps its relative place at the end,
            // so a partial list cannot silently delete positions.
            foreach ($owned as $id) {
                if (! in_array($id, $ordered, true)) {
                    $ordered[] = $id;
                }
            }

            // Park out of the way of the "exactly one row at position 0" index.
            $offset = -count($ordered) - 1;

            foreach ($ordered as $index => $id) {
                ProductMedia::query()->whereKey($id)->update(['position' => $offset - $index]);
            }

            foreach ($ordered as $index => $id) {
                ProductMedia::query()->whereKey($id)->update(['position' => $index]);
            }
        });
    }

    /**
     * Removes an image and closes the gap it leaves.
     *
     * Deleting the cover has to promote another image, otherwise the product has no
     * row at position 0 and every listing card falls back to a placeholder while the
     * seller still sees images in their gallery.
     */
    public function delete(ProductMedia $media): void
    {
        $product = $media->product;

        DB::transaction(function () use ($media, $product): void {
            $media->delete();

            if ($product !== null) {
                $remaining = $product->media()->orderBy('position')->pluck('id')->all();
                $this->reorder($product, $remaining);
            }
        });

        // The bytes go only after the row is gone: an orphaned object is a cleanup job,
        // an orphaned row is a broken image on a live catalogue page.
        Storage::disk($media->disk)->delete($media->storage_path);
    }

    private function nextPosition(Product $product): int
    {
        $highest = $product->media()->max('position');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function extensionFor(UploadedFile $file): string
    {
        // Derived from the detected MIME type, not from the client's filename: the
        // extension ends up in a public URL, and ".php" in that URL is somebody else's
        // misconfiguration waiting to matter.
        return match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function assertAcceptable(Product $product, UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Yalnızca JPEG, PNG ve WebP görseller yüklenebilir.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Görsel boyutu 8 MB sınırını aşıyor.');
        }

        if (@getimagesize($file->getRealPath()) === false) {
            // A file can claim image/jpeg in its headers and still not decode. Serving
            // it would put a broken thumbnail on the catalogue.
            throw new RuntimeException('Dosya geçerli bir görsel değil.');
        }

        if ($product->media()->count() >= self::MAX_PER_PRODUCT) {
            throw new RuntimeException('Bir ürüne en fazla '.self::MAX_PER_PRODUCT.' görsel eklenebilir.');
        }
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.public_disk', config('filesystems.default'));
    }
}

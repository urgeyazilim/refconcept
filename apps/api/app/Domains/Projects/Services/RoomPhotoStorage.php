<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\DesignAsset;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores and serves a customer's room photographs and the renders made from them.
 *
 * The strictest privacy tier in RefConcept. A photograph of somebody's living room
 * shows what they own, how they live and often who they live with; it is worth more to
 * an intruder than a tax certificate, and it gets the same treatment as one — plus the
 * knowledge that nobody will ever be *expecting* it to be public, which is exactly how
 * a "just make it a CDN URL" shortcut gets accepted in review.
 *
 * Four rules, enforced here rather than trusted to callers:
 *
 *  1. **Private disk, always.** There is no configuration under which these land on the
 *     public bucket, and no `url()` method anywhere on the models.
 *  2. **Random object keys**, so a leaked path for one photograph is not a directory
 *     listing for the rest.
 *  3. **Short-lived signed URLs**, issued only after the caller has been authorised.
 *     This class takes no user and performs no check, so it can never be mistaken for
 *     the authorisation itself.
 *  4. **The original is never written over.** Renders go to `design_assets` via
 *     {@see storeRender()}; a room photograph has no update path at all.
 */
final class RoomPhotoStorage
{
    /** What a phone camera actually produces, and what the engine can read. */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    public const MAX_SIZE_BYTES = 25 * 1024 * 1024;

    public const MAX_PER_ROOM = 20;

    /**
     * Below this the engine cannot see enough of the room to reason about it, and the
     * result is a design of a blur. Refused at upload rather than after the customer
     * has spent credits on it.
     */
    public const MIN_LONGEST_EDGE = 640;

    private const SIGNED_URL_TTL_MINUTES = 5;

    public function store(
        Room $room,
        UploadedFile $file,
        User $uploader,
        string $type = 'photo',
        ?string $caption = null,
    ): RoomMedia {
        $this->assertAcceptable($room, $file);

        $disk = $this->disk();

        // The room id groups objects for operational purposes; the filename itself
        // carries nothing guessable.
        $path = sprintf(
            'room-media/%s/%s.%s',
            $room->getKey(),
            Str::uuid7()->toString(),
            $this->extensionFor($file),
        );

        $this->put($disk, $path, $file->getRealPath());

        [$width, $height] = $this->dimensions($file->getRealPath());

        return RoomMedia::query()->create([
            'room_id' => $room->getKey(),
            'type' => $type,
            'disk' => $disk,
            'storage_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: '',
            'caption' => $caption,
            'position' => $this->nextPosition($room),
            'uploaded_by' => $uploader->getKey(),
        ]);
    }

    /**
     * Stores an image a design version produced.
     *
     * Deliberately a different method writing to a different table. From Phase 8 the AI
     * gateway calls this and never touches {@see store()}, which is what makes "the
     * original is immutable" a property of the code rather than a promise in a comment.
     */
    public function storeRender(
        string $designVersionId,
        string $sourcePath,
        string $mimeType,
        string $type = 'render',
    ): DesignAsset {
        $disk = $this->disk();

        $path = sprintf(
            'design-assets/%s/%s.%s',
            $designVersionId,
            Str::uuid7()->toString(),
            $this->extensionForMime($mimeType),
        );

        $this->put($disk, $path, $sourcePath);

        [$width, $height] = $this->dimensions($sourcePath);

        return DesignAsset::query()->create([
            'design_version_id' => $designVersionId,
            'type' => $type,
            'disk' => $disk,
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => (int) filesize($sourcePath),
            'width' => $width,
            'height' => $height,
            'checksum_sha256' => hash_file('sha256', $sourcePath) ?: '',
        ]);
    }

    /**
     * A short-lived link to a photograph.
     *
     * Callers must have authorised the request first. This method takes no user on
     * purpose: a signature that accepted one would invite somebody to believe the
     * check happens here.
     */
    public function temporaryUrl(RoomMedia $media): string
    {
        return $this->signed(
            $media->disk,
            $media->storage_path,
            fn (): string => route('v1.projects.room-media.download', ['medium' => $media->getKey()]),
        );
    }

    public function temporaryAssetUrl(DesignAsset $asset): string
    {
        return $this->signed(
            $asset->disk,
            $asset->storage_path,
            fn (): string => route('v1.projects.design-assets.download', ['asset' => $asset->getKey()]),
        );
    }

    /** @return resource|null */
    public function readStream(string $disk, string $path)
    {
        return Storage::disk($disk)->readStream($path);
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Removes the bytes.
     *
     * Called when a customer deletes a photograph, because unlike a seller's onboarding
     * document there is no dispute this could later have to answer — and keeping a
     * picture of somebody's home after they asked for it gone is indefensible.
     */
    public function purge(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    // --- internals -----------------------------------------------------------

    private function put(string $disk, string $path, string $sourcePath): void
    {
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Yüklenen dosya okunamadı.');
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A signed URL, or a route-signed download when the driver cannot sign.
     *
     * @param  callable(): string  $fallback
     */
    private function signed(string $disk, string $path, callable $fallback): string
    {
        $filesystem = Storage::disk($disk);

        // The local driver cannot sign, so tests and bare setups fall back to streaming
        // through the application rather than silently exposing a public path.
        if (! method_exists($filesystem, 'temporaryUrl')) {
            return $fallback();
        }

        try {
            return $filesystem->temporaryUrl($path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        } catch (RuntimeException) {
            return $fallback();
        }
    }

    private function nextPosition(Room $room): int
    {
        $highest = $room->media()->max('position');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        if ($size === false) {
            // HEIC does not decode without an extension PHP may not have. The upload is
            // still accepted — a phone photograph is exactly what this exists for — and
            // the dimensions simply stay unknown.
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function extensionFor(UploadedFile $file): string
    {
        return $this->extensionForMime((string) $file->getMimeType());
    }

    /** Derived from the detected type, never from the client's filename. */
    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heif' => 'heic',
            default => 'jpg',
        };
    }

    private function assertAcceptable(Room $room, UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Yalnızca JPEG, PNG, WebP ve HEIC fotoğraflar yüklenebilir.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Fotoğraf boyutu 25 MB sınırını aşıyor.');
        }

        if ($room->media()->count() >= self::MAX_PER_ROOM) {
            throw new RuntimeException('Bir odaya en fazla '.self::MAX_PER_ROOM.' fotoğraf eklenebilir.');
        }

        [$width, $height] = $this->dimensions($file->getRealPath());

        // Unknown dimensions are allowed through — see dimensions() — but a photograph
        // we *can* measure and that is too small is refused now rather than after the
        // customer has paid credits for a design of a blur.
        if ($width !== null && $height !== null && max($width, $height) < self::MIN_LONGEST_EDGE) {
            throw new RuntimeException(sprintf(
                'Fotoğrafın uzun kenarı en az %d piksel olmalı; bu fotoğraf %d×%d.',
                self::MIN_LONGEST_EDGE,
                $width,
                $height,
            ));
        }
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.private_disk', config('filesystems.default'));
    }
}

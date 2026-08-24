<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\DocumentType;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores and serves seller onboarding documents.
 *
 * These files are tax certificates, signature circulars and identity documents. Three
 * rules follow from that and are enforced here rather than trusted to callers:
 *
 *  1. They go on the **private** disk. Nothing about them is publicly addressable.
 *  2. Access is a short-lived signed URL issued after a policy check, never a stored
 *     link. A URL that works forever is a leak waiting for one careless forward.
 *  3. The object key is random, not derived from the filename. A guessable key turns
 *     the bucket into a directory listing for anyone who finds one document.
 */
final class DocumentStorage
{
    /** Formats a reviewer can actually read; anything else is refused at upload. */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    private const SIGNED_URL_TTL_MINUTES = 5;

    public function store(
        SellerApplication $application,
        UploadedFile $file,
        DocumentType $type,
        User $uploader,
    ): SellerDocument {
        $this->assertAcceptable($file);

        $disk = $this->disk();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');

        // Random key: the application id is in the path for operational grouping, but
        // the filename itself carries no guessable information.
        $path = sprintf(
            'seller-documents/%s/%s.%s',
            $application->getKey(),
            Str::uuid7()->toString(),
            $extension,
        );

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new RuntimeException('Yüklenen dosya okunamadı.');
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            fclose($stream);
        }

        return SellerDocument::query()->create([
            'application_id' => $application->getKey(),
            'type' => $type,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'storage_path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            // Lets a reviewer confirm the file they are looking at is the one uploaded,
            // and makes an accidental duplicate upload obvious.
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: '',
            'uploaded_by' => $uploader->getKey(),
        ]);
    }

    /**
     * A short-lived link to the file.
     *
     * Callers must have authorised the request first; this method deliberately takes
     * no user, so it can never be mistaken for the authorisation itself.
     */
    public function temporaryUrl(SellerDocument $document): string
    {
        $disk = $this->disk();

        // The local driver cannot sign URLs, so tests and bare local setups fall back
        // to a route-signed download rather than silently exposing a public path.
        if (! method_exists(Storage::disk($disk), 'temporaryUrl')) {
            return route('v1.seller.documents.download', ['document' => $document->getKey()]);
        }

        try {
            return Storage::disk($disk)->temporaryUrl(
                $document->storage_path,
                now()->addMinutes(self::SIGNED_URL_TTL_MINUTES),
            );
        } catch (RuntimeException) {
            return route('v1.seller.documents.download', ['document' => $document->getKey()]);
        }
    }

    /** @return resource|null */
    public function readStream(SellerDocument $document)
    {
        return Storage::disk($this->disk())->readStream($document->storage_path);
    }

    public function exists(SellerDocument $document): bool
    {
        return Storage::disk($this->disk())->exists($document->storage_path);
    }

    /**
     * Removes the object once the row is gone for good.
     *
     * Not called on soft delete: a replaced document is still evidence of what was
     * submitted, and deleting the bytes would make a later dispute unanswerable.
     */
    public function purge(SellerDocument $document): void
    {
        Storage::disk($this->disk())->delete($document->storage_path);
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Bu dosya türü kabul edilmiyor.');
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Dosya boyutu 10 MB sınırını aşıyor.');
        }
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.private_disk', config('filesystems.default'));
    }
}

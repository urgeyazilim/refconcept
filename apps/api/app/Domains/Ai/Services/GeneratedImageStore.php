<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts an image a model produced somewhere the application can point at.
 *
 * Providers hand back either a URL that expires within the hour or a blob of base64,
 * and neither is something to store in a database row a customer will open next
 * month. Both are written here, once, to the public bucket, and what the rest of the
 * application ever sees is a durable URL.
 *
 * Public rather than private, unlike the room photograph that produced it: a render is
 * something a customer shares with a partner and a contractor, and the original
 * photograph of somebody's living room is not. The key is random either way, so a
 * render is not discoverable without the link.
 */
final class GeneratedImageStore
{
    /**
     * Writes decoded bytes and returns the URL to them.
     *
     * The extension comes from the declared MIME type rather than from anything the
     * provider named the file: a provider is a remote system, and a filename from a
     * remote system is an input, not a fact.
     */
    public function putBinary(string $bytes, string $mimeType = 'image/png'): string
    {
        $path = sprintf(
            'ai-renders/%s/%s.%s',
            now()->format('Y/m'),
            Str::uuid7()->toString(),
            $this->extensionFor($mimeType),
        );

        Storage::disk($this->disk())->put($path, $bytes, 'public');

        return (string) Storage::disk($this->disk())->url($path);
    }

    /** Convenience for the shape providers actually return. */
    public function putBase64(string $base64, string $mimeType = 'image/png'): ?string
    {
        $bytes = base64_decode($base64, true);

        // A provider that sent something that is not base64 has not sent an image, and
        // writing the raw string would produce a URL that resolves to a broken picture
        // — which is far harder to diagnose than an attempt that reports no image.
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return $this->putBinary($bytes, $mimeType);
    }

    private function extensionFor(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    private function disk(): string
    {
        return (string) config('refconcept.storage.public_disk', config('filesystems.default'));
    }
}

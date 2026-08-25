<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Somewhere to put an image a model produced, until whoever asked for it takes it.
 *
 * Providers hand back inline bytes, and those bytes cannot travel in a job's `output`
 * column — a megabyte of base64 in a JSON field is a table nobody can read and a query
 * nobody can run. So they are written to disk here and the job carries a reference.
 *
 * **The private disk, not the public one.** This was public in its first form, and that
 * was wrong: what passes through here is a render of the inside of somebody's home, and
 * leaving a permanently readable copy on an anonymously-accessible bucket is not made
 * acceptable by the key being random. The consumer copies what it wants to its own
 * permanent home and calls {@see discard()}; nothing is left behind either way.
 *
 * A reference is a path, not a URL. Nothing outside the server can resolve it, which is
 * the point — the only way to see one of these images is to be handed a signed link by
 * something that checked who was asking.
 */
final class GeneratedImageStore
{
    /** Staging, not storage: everything here is expected to be claimed and removed. */
    private const PREFIX = 'ai-staging';

    /**
     * Writes bytes and returns the reference to them.
     *
     * The extension comes from the declared MIME type rather than from anything the
     * provider named the file: a provider is a remote system, and a filename from a
     * remote system is an input rather than a fact.
     */
    public function stash(string $bytes, string $mimeType = 'image/png'): string
    {
        if ($bytes === '') {
            throw new RuntimeException('Sağlayıcıdan boş bir görsel geldi.');
        }

        $path = sprintf(
            '%s/%s/%s.%s',
            self::PREFIX,
            now()->format('Y/m/d'),
            Str::uuid7()->toString(),
            $this->extensionFor($mimeType),
        );

        Storage::disk($this->disk())->put($path, $bytes);

        return $path;
    }

    /**
     * Decodes and stashes, tolerating what providers actually send.
     *
     * Returns null rather than throwing when the payload is not base64 at all: that is a
     * malformed answer, and the adapter above turns it into a failure the gateway can
     * classify — which is more useful than an exception from a storage class.
     */
    public function stashBase64(string $base64, string $mimeType = 'image/png'): ?string
    {
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return $this->stash($bytes, $mimeType);
    }

    /** @return resource|null */
    public function read(string $reference)
    {
        $stream = Storage::disk($this->disk())->readStream($reference);

        return is_resource($stream) ? $stream : null;
    }

    public function exists(string $reference): bool
    {
        return Storage::disk($this->disk())->exists($reference);
    }

    public function mimeTypeOf(string $reference): string
    {
        return match (pathinfo($reference, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    /**
     * Removes a staged image once somebody has taken a copy.
     *
     * Deliberately forgiving: a reference that has already gone is not an error worth
     * failing a finished render over, and the alternative is a pipeline that succeeds and
     * then throws while tidying up.
     */
    public function discard(string $reference): void
    {
        if (! str_starts_with($reference, self::PREFIX.'/')) {
            // Only ever deletes its own. A caller passing something else is a bug, and it
            // must not become a bug that removes a customer's photograph.
            return;
        }

        Storage::disk($this->disk())->delete($reference);
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
        return (string) config('refconcept.storage.private_disk', config('filesystems.default'));
    }
}

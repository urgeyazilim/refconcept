<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads an image and hands it to a provider as bytes, never as a link.
 *
 * The gateway used to pass providers a signed URL and let them fetch it. That was wrong
 * twice over, and both ways were invisible until somebody uploaded a photograph.
 *
 * **It does not work.** Gemini's `file_data.file_uri` accepts a URI from Google's own Files
 * API, not an arbitrary URL. Every design generation failed with *"Cannot fetch content from
 * the provided URL"*, which the platform then showed a customer as a problem with their
 * photograph.
 *
 * **It should not work.** Room photographs live on the private disk precisely so that no URL
 * to one ever leaves this system — that is the whole rule from Phase 5, and handing a
 * third party a fetchable link to somebody's home would have quietly broken it while
 * appearing to be an optimisation.
 *
 * So the bytes are read here, inside our own network, and sent inline. The original comment
 * worried about holding a photograph in memory on a request somebody is waiting on; that is
 * a real cost and it is the smaller one. It is also bounded — see MAX_BYTES.
 */
final class InlineImageLoader
{
    /**
     * The most we will inline, per image.
     *
     * Gemini's own request ceiling is 20MB for everything together, and a room photograph
     * that large is a photograph nobody needed at that size. Refusing here is better than
     * sending a request the provider rejects for a reason that reads like our fault.
     */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Reads each source and returns it as bytes.
     *
     * A source is `{disk, path}` for anything we hold — which is everything a design is
     * built from — and a URL only for something genuinely elsewhere.
     *
     * @param  array<int, array{disk?: string, path?: string, url?: string}|string>  $sources
     * @return list<array{mime: string, data: string, width: int, height: int}> base64
     *                                                                          payloads, in order, each with the pixel size it was read at
     */
    public function load(array $sources, int $timeoutSeconds = 20): array
    {
        $images = [];

        foreach ($sources as $source) {
            $image = is_array($source)
                ? $this->fromSource($source, $timeoutSeconds)
                : $this->fromUrl((string) $source, $timeoutSeconds);

            if ($image !== null) {
                $images[] = $image;
            }
        }

        /*
         * Said out loud when nothing could be read.
         *
         * This exact silence hid a real defect: the loader fetched signed URLs over HTTP,
         * the URLs were signed for the browser's hostname, and inside the container that
         * name resolves to the container itself. Every fetch failed, `load()` returned an
         * empty list, and the render carried on with no images — producing a handsome room
         * that was not the customer's, with nothing anywhere saying why.
         */
        if ($images === [] && $sources !== []) {
            Log::warning('AI çağrısına hiçbir görsel eklenemedi.', ['istenen' => count($sources)]);
        }

        return $images;
    }

    /**
     * @param  array{disk?: string, path?: string, url?: string}  $source
     * @return array{mime: string, data: string, width: int, height: int}|null
     */
    private function fromSource(array $source, int $timeoutSeconds): ?array
    {
        $path = (string) ($source['path'] ?? '');

        if ($path === '') {
            return $this->fromUrl((string) ($source['url'] ?? ''), $timeoutSeconds);
        }

        /*
         * Read straight off the disk rather than over HTTP.
         *
         * Faster, and immune to the whole class of problem that a URL brings: which host
         * name resolves from where, whether a signature is still valid, whether the link
         * has expired between being made and being used.
         */
        $disk = (string) ($source['disk'] ?? config('refconcept.storage.private_disk'));

        try {
            $body = Storage::disk($disk)->get($path);
        } catch (Throwable $e) {
            Log::warning('AI görseli diskten okunamadı.', ['disk' => $disk, 'reason' => $e->getMessage()]);

            return null;
        }

        return is_string($body) ? $this->describe($body, null) : null;
    }

    /**
     * @return array{mime: string, data: string, width: int, height: int}|null
     */
    private function fromUrl(string $url, int $timeoutSeconds): ?array
    {
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout($timeoutSeconds)->get($url);
        } catch (Throwable $e) {
            /*
             * Logged without the URL.
             *
             * A signed link to a customer's room photograph is exactly the thing that must
             * not end up in a log file that more people can read than can read the photo.
             */
            Log::warning('AI görseli okunamadı.', ['reason' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AI görseli okunamadı.', ['status' => $response->status()]);

            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            Log::warning('AI görseli atlandı.', [
                'reason' => $body === '' ? 'boş' : 'çok büyük',
                'bytes' => strlen($body),
            ]);

            return null;
        }

        return $this->describe($body, $response->header('Content-Type'));
    }

    /**
     * Bytes, plus the two things a caller needs to know about them.
     *
     * The pixel size travels with the image because an image model given no aspect ratio
     * answers in its own default — a wide cinematic frame — and a room photographed in
     * portrait comes back as a different, wider room. Not cropped: different, because the
     * model fills whatever frame it was asked for.
     *
     * @return array{mime: string, data: string, width: int, height: int}
     */
    private function describe(string $body, ?string $contentType): array
    {
        $size = @getimagesizefromstring($body);

        return [
            // Read from the bytes rather than guessed from a filename: a signed URL carries
            // a query string, and guessing from the path gets `image/jpeg` for a PNG.
            'mime' => $this->mimeFor($contentType, $body),
            'data' => base64_encode($body),
            'width' => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
        ];
    }

    private function mimeFor(?string $header, string $body): string
    {
        $declared = mb_strtolower(trim(explode(';', (string) $header)[0]));

        if (str_starts_with($declared, 'image/')) {
            return $declared;
        }

        // Storage that answers `application/octet-stream` is common enough to be worth
        // reading the magic bytes rather than sending a mime the provider will refuse.
        return match (true) {
            str_starts_with($body, "\x89PNG") => 'image/png',
            str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP') => 'image/webp',
            str_starts_with($body, 'GIF8') => 'image/gif',
            default => 'image/jpeg',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * @param  array<int, string>  $urls
     * @return list<array{mime: string, data: string}> base64 payloads, in order
     */
    public function load(array $urls, int $timeoutSeconds = 20): array
    {
        $images = [];

        foreach ($urls as $url) {
            $image = $this->one((string) $url, $timeoutSeconds);

            if ($image !== null) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    private function one(string $url, int $timeoutSeconds): ?array
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

        return [
            // From the response rather than from the file extension: a signed URL carries a
            // query string, and guessing from the path gets `image/jpeg` for a PNG.
            'mime' => $this->mimeFor($response->header('Content-Type'), $body),
            'data' => base64_encode($body),
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

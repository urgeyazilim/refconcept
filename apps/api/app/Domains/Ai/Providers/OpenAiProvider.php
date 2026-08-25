<?php

declare(strict_types=1);

namespace App\Domains\Ai\Providers;

use App\Domains\Ai\Contracts\AiProvider;
use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Ai\Services\GeneratedImageStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to OpenAI's chat and image endpoints.
 *
 * The adapter's whole job is translation, in both directions: an {@see AiCall} into the
 * JSON this provider expects, and whatever comes back — including every way it can go
 * wrong — into an {@see AiResult} the gateway can reason about. It does not retry, does
 * not log, does not price anything and does not decide whether a failure is worth
 * another go. All of that is the gateway's, in one place.
 *
 * Nothing here throws for a provider-side problem. A 429 and a socket timeout are
 * *answers*: the gateway retries the first and gives up faster on a refusal, and an
 * exception would throw away the classification that tells them apart.
 */
final class OpenAiProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(private readonly GeneratedImageStore $images) {}

    public function driver(): string
    {
        return 'openai';
    }

    public function supports(AiCall $call): bool
    {
        if ($call->apiKey === null || $call->apiKey === '') {
            return false;
        }

        return $call->model->modality->canServe($call->modality());
    }

    public function execute(AiCall $call): AiResult
    {
        if (! $this->supports($call)) {
            /*
             * Reported as an invalid request rather than an authentication failure,
             * because nothing was authenticated — the call never left the building. The
             * distinction is what stops the gateway burning a fallback attempt on a
             * misconfiguration the fallback shares.
             */
            return AiResult::failure(
                AiFailureKind::InvalidRequest,
                'OpenAI çağrısı için geçerli bir anahtar veya uygun modalite yok.',
            );
        }

        try {
            return $call->modality() === AiModality::Image
                ? $this->generateImage($call)
                : $this->generateText($call);
        } catch (ConnectionException $e) {
            // Covers both a refused socket and a client-side timeout; the message
            // distinguishes them for a person, the kind does for the gateway.
            return AiResult::failure(
                str_contains(strtolower($e->getMessage()), 'timed out')
                    ? AiFailureKind::Timeout
                    : AiFailureKind::NetworkError,
                $e->getMessage(),
            );
        } catch (Throwable $e) {
            return AiResult::failure(AiFailureKind::ProviderError, $e->getMessage());
        }
    }

    // --- internals -----------------------------------------------------------

    private function generateText(AiCall $call): AiResult
    {
        $messages = [];

        if ($call->systemPrompt !== null && $call->systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $call->systemPrompt];
        }

        $content = [['type' => 'text', 'text' => $call->prompt]];

        foreach ($call->imageUrls as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        $messages[] = ['role' => 'user', 'content' => $content];

        $payload = [
            'model' => $call->model->code,
            'messages' => $messages,
            'temperature' => $call->temperature(),
        ];

        if ($call->model->max_output_tokens !== null) {
            $payload['max_tokens'] = $call->model->max_output_tokens;
        }

        /*
         * `json_object` rather than a full schema handoff: the gateway validates the
         * shape itself, against the same schema whichever provider ran the call. Asking
         * two providers to enforce it in their own dialects would mean two definitions
         * of "valid" and a task that passes on one and fails on the other.
         */
        if ($call->expectsStructuredOutput() && $call->model->supports_structured_output) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->client($call)->post('/chat/completions', $payload);

        if ($response->failed()) {
            return $this->translateFailure($response);
        }

        $body = $response->json() ?? [];

        $text = data_get($body, 'choices.0.message.content');
        $finishReason = (string) data_get($body, 'choices.0.finish_reason', '');

        if ($finishReason === 'content_filter') {
            return AiResult::failure(
                AiFailureKind::SafetyRefusal,
                'OpenAI içerik filtresine takıldı.',
                httpStatus: $response->status(),
                inputTokens: (int) data_get($body, 'usage.prompt_tokens', 0),
                outputTokens: (int) data_get($body, 'usage.completion_tokens', 0),
            );
        }

        return AiResult::success(
            text: is_string($text) ? $text : null,
            inputTokens: (int) data_get($body, 'usage.prompt_tokens', 0),
            outputTokens: (int) data_get($body, 'usage.completion_tokens', 0),
            httpStatus: $response->status(),
        );
    }

    private function generateImage(AiCall $call): AiResult
    {
        $response = $this->client($call)->post('/images/generations', [
            'model' => $call->model->code,
            'prompt' => $call->prompt,
            'n' => 1,
            'size' => (string) ($call->options['size'] ?? '1024x1024'),
        ]);

        if ($response->failed()) {
            return $this->translateFailure($response);
        }

        $urls = [];
        $refs = [];

        /** @var array<int, array<string, mixed>> $items */
        $items = (array) data_get($response->json() ?? [], 'data', []);

        foreach ($items as $item) {
            /*
             * Inline bytes are written to the private disk here and carried onward as a
             * reference. They cannot travel in the job row — a megabyte of base64 in a
             * JSON column is a table nobody can read — and they must not be staged
             * publicly, because what passes through is a picture of somebody's home.
             */
            if (isset($item['b64_json']) && is_string($item['b64_json'])) {
                $stashed = $this->images->stashBase64($item['b64_json']);

                if ($stashed !== null) {
                    $refs[] = $stashed;
                }

                continue;
            }

            // The URL form this provider offers expires within the hour, so it is passed
            // on for the caller to fetch promptly rather than stored as if it were durable.
            if (isset($item['url']) && is_string($item['url'])) {
                $urls[] = $item['url'];
            }
        }

        if ($urls === [] && $refs === []) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'OpenAI yanıtında kullanılabilir bir görsel yok.',
                httpStatus: $response->status(),
            );
        }

        return AiResult::success(
            imageUrls: $urls,
            imageRefs: $refs,
            imageCount: count($urls) + count($refs),
            httpStatus: $response->status(),
        );
    }

    /**
     * Turns an HTTP failure into the kind that decides what happens next.
     *
     * Status code first because it is the reliable signal, message second because it is
     * the only thing that separates a genuine bad request from a safety refusal — both
     * of which this provider reports as a 400.
     */
    private function translateFailure(Response $response): AiResult
    {
        $status = $response->status();
        $message = (string) (data_get($response->json(), 'error.message') ?? $response->body());

        $kind = match (true) {
            $status === 401 || $status === 403 => AiFailureKind::AuthenticationFailed,
            $status === 408 || $status === 504 => AiFailureKind::Timeout,
            $status === 429 => AiFailureKind::RateLimited,
            $status >= 500 => AiFailureKind::ProviderError,
            $status === 400 && $this->looksLikeSafety($message) => AiFailureKind::SafetyRefusal,
            default => AiFailureKind::InvalidRequest,
        };

        return AiResult::failure($kind, $message, httpStatus: $status);
    }

    private function looksLikeSafety(string $message): bool
    {
        $haystack = strtolower($message);

        foreach (['safety', 'content_policy', 'content policy', 'moderation'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function client(AiCall $call): PendingRequest
    {
        $baseUrl = $call->model->provider?->base_url ?: self::DEFAULT_BASE_URL;

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->withToken((string) $call->apiKey)
            ->timeout($call->timeoutSeconds)
            /*
             * Connecting gets a short leash of its own: a provider that is simply
             * unreachable should not consume the whole budget a slow-but-working
             * provider is allowed.
             */
            ->connectTimeout(min(10, $call->timeoutSeconds))
            ->acceptJson();
    }
}

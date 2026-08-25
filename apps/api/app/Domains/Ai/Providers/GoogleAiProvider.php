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
 * Talks to Google's Generative Language API.
 *
 * One endpoint, `:generateContent`, serves text, vision and image generation alike —
 * which of the three happens is decided by the model and read back out of the parts
 * the answer contains. That is why this adapter, unlike {@see OpenAiProvider}, does not
 * branch on modality before the call: there is nothing to branch to.
 *
 * Two things about this provider need saying because they are not obvious from its
 * documentation and both cost a debugging session to discover:
 *
 *  - **A refusal arrives as a 200.** `finishReason: SAFETY` on an otherwise ordinary
 *    successful response, or a `promptFeedback.blockReason` with no candidates at all.
 *    Checking only the status code would produce an empty answer classified as success,
 *    and a customer told nothing at all about why their render is blank.
 *  - **The key goes in a header, not the query string.** `?key=` works and is what most
 *    examples show, but a query string is the part of a URL that ends up in access logs
 *    and error trackers. A header does not.
 */
final class GoogleAiProvider implements AiProvider
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(private readonly GeneratedImageStore $images) {}

    public function driver(): string
    {
        return 'google';
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
            return AiResult::failure(
                AiFailureKind::InvalidRequest,
                'Google çağrısı için geçerli bir anahtar veya uygun modalite yok.',
            );
        }

        try {
            /*
             * Embeddings are a different endpoint on the same API, and the only place this
             * adapter branches on modality. Everything else — text, vision, image — comes
             * back through :generateContent, which is why the rest of the class does not
             * ask what kind of call it is making.
             */
            if ($call->modality() === AiModality::Embedding) {
                return $this->embed($call);
            }

            $response = $this->client($call)->post(
                sprintf('/models/%s:generateContent', $call->model->code),
                $this->payloadFor($call),
            );
        } catch (ConnectionException $e) {
            return AiResult::failure(
                str_contains(strtolower($e->getMessage()), 'timed out')
                    ? AiFailureKind::Timeout
                    : AiFailureKind::NetworkError,
                $e->getMessage(),
            );
        } catch (Throwable $e) {
            return AiResult::failure(AiFailureKind::ProviderError, $e->getMessage());
        }

        if ($response->failed()) {
            return $this->translateFailure($response);
        }

        return $this->readAnswer($response, $call);
    }

    // --- internals -----------------------------------------------------------

    /**
     * Turns text into a vector.
     *
     * `RETRIEVAL_DOCUMENT` rather than the default, because that is what these vectors are
     * for: a catalogue somebody will search. The asymmetric variants matter — a query
     * embedded as a document sits in a slightly different place from the same words
     * embedded as a query, and the difference is measurable in what comes back.
     */
    private function embed(AiCall $call): AiResult
    {
        $response = $this->client($call)->post(
            sprintf('/models/%s:embedContent', $call->model->code),
            [
                'model' => 'models/'.$call->model->code,
                'content' => ['parts' => [['text' => $call->prompt]]],
                'taskType' => (string) ($call->options['task_type'] ?? 'RETRIEVAL_DOCUMENT'),
            ],
        );

        if ($response->failed()) {
            return $this->translateFailure($response);
        }

        /** @var array<int, float> $values */
        $values = (array) data_get($response->json() ?? [], 'embedding.values', []);

        if ($values === []) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'Google boş bir vektör döndürdü.',
                httpStatus: $response->status(),
            );
        }

        return AiResult::success(
            embedding: array_map(static fn ($value): float => (float) $value, $values),
            inputTokens: (int) ceil(mb_strlen($call->prompt) / 4),
            httpStatus: $response->status(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(AiCall $call): array
    {
        $parts = [['text' => $call->prompt]];

        foreach ($call->imageUrls as $url) {
            /*
             * Sent by reference rather than inlined. Inlining would mean this process
             * downloading every room photograph and holding it in memory to base64 it,
             * on a request a customer is waiting on.
             */
            $parts[] = ['file_data' => ['mime_type' => 'image/jpeg', 'file_uri' => $url]];
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => $call->temperature(),
            ],
        ];

        if ($call->systemPrompt !== null && $call->systemPrompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $call->systemPrompt]]];
        }

        if ($call->model->max_output_tokens !== null) {
            $payload['generationConfig']['maxOutputTokens'] = $call->model->max_output_tokens;
        }

        // Only the MIME type, not the schema: the gateway checks the shape itself so
        // that "valid" means the same thing whichever provider ran the call.
        if ($call->expectsStructuredOutput() && $call->model->supports_structured_output) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        return $payload;
    }

    /**
     * Reads a 200 that may or may not actually contain an answer.
     */
    private function readAnswer(Response $response, AiCall $call): AiResult
    {
        $body = $response->json() ?? [];

        $inputTokens = (int) data_get($body, 'usageMetadata.promptTokenCount', 0);
        $outputTokens = (int) data_get($body, 'usageMetadata.candidatesTokenCount', 0);

        // No candidates at all: the prompt itself was blocked before generation.
        $blockReason = data_get($body, 'promptFeedback.blockReason');

        if (is_string($blockReason) && $blockReason !== '') {
            return AiResult::failure(
                AiFailureKind::SafetyRefusal,
                'Google istemi güvenlik nedeniyle reddetti: '.$blockReason,
                httpStatus: $response->status(),
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
            );
        }

        $finishReason = (string) data_get($body, 'candidates.0.finishReason', '');

        if (in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION'], true)) {
            return AiResult::failure(
                AiFailureKind::SafetyRefusal,
                'Google yanıtı güvenlik nedeniyle durdurdu: '.$finishReason,
                httpStatus: $response->status(),
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
            );
        }

        /** @var array<int, array<string, mixed>> $parts */
        $parts = (array) data_get($body, 'candidates.0.content.parts', []);

        $text = '';
        $refs = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];

                continue;
            }

            $inline = $part['inline_data'] ?? $part['inlineData'] ?? null;

            if (is_array($inline) && isset($inline['data']) && is_string($inline['data'])) {
                // Written to the private disk and carried on as a reference: the bytes
                // cannot go in the job row, and a render of somebody's home must not be
                // staged where anybody with the link can read it.
                $stashed = $this->images->stashBase64(
                    $inline['data'],
                    (string) ($inline['mime_type'] ?? $inline['mimeType'] ?? 'image/png'),
                );

                if ($stashed !== null) {
                    $refs[] = $stashed;
                }
            }
        }

        /*
         * `MAX_TOKENS` is reported after the truncated text has been collected, not
         * before: half a design plan is still worth showing a customer, and for a
         * structured task the validator will reject the truncated JSON on its own — with
         * a message about the shape, which is the more useful complaint.
         */
        if ($text === '' && $refs === []) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                $finishReason === ''
                    ? 'Google boş bir yanıt döndürdü.'
                    : 'Google yanıtı içerik olmadan sonlandı: '.$finishReason,
                httpStatus: $response->status(),
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
            );
        }

        return AiResult::success(
            text: $text !== '' ? $text : null,
            imageRefs: $refs,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            imageCount: count($refs),
            httpStatus: $response->status(),
        );
    }

    private function translateFailure(Response $response): AiResult
    {
        $status = $response->status();
        $message = (string) (data_get($response->json(), 'error.message') ?? $response->body());

        $kind = match (true) {
            $status === 401 || $status === 403 => AiFailureKind::AuthenticationFailed,
            $status === 408 || $status === 504 => AiFailureKind::Timeout,
            // 503 is this provider's "model is overloaded", which is worth another go.
            $status === 429 => AiFailureKind::RateLimited,
            $status >= 500 => AiFailureKind::ProviderError,
            default => AiFailureKind::InvalidRequest,
        };

        return AiResult::failure($kind, $message, httpStatus: $status);
    }

    private function client(AiCall $call): PendingRequest
    {
        $baseUrl = $call->model->provider?->base_url ?: self::DEFAULT_BASE_URL;

        return Http::baseUrl(rtrim($baseUrl, '/'))
            // Header rather than `?key=`: query strings are logged, headers are not.
            ->withHeaders(['x-goog-api-key' => (string) $call->apiKey])
            ->timeout($call->timeoutSeconds)
            ->connectTimeout(min(10, $call->timeoutSeconds))
            ->acceptJson();
    }
}

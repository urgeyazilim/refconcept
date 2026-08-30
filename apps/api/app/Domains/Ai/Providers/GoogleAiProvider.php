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

    /**
     * The vector width the catalogue column is built for.
     *
     * Kept here as well as in the migration because this provider has to be *told* — its
     * models default to a much wider vector, and a mismatch is not a soft failure: the
     * insert is refused.
     */
    private const EMBEDDING_DIMENSIONS = 768;

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
                /*
                 * The width the column expects. This model's native output is far wider,
                 * and asking for the wider vector would produce numbers PostgreSQL refuses
                 * — the dimension is fixed in the schema, so it has to be asked for here.
                 */
                'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
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
            embedding: $this->normalise(array_map(static fn ($value): float => (float) $value, $values)),
            inputTokens: (int) ceil(mb_strlen($call->prompt) / 4),
            httpStatus: $response->status(),
        );
    }

    /**
     * Scales a vector to unit length.
     *
     * Necessary rather than tidy. This provider returns a normalised vector only at its
     * native width; ask for a narrower one — as the schema requires — and the truncated
     * result is *not* normalised. Cosine distance on unnormalised vectors ranks a long
     * description above a good one, and the similarity percentage computed from it would be
     * meaningless. Doing it here means every vector in the column is comparable however it
     * was produced.
     *
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $value): float => $value ** 2, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $magnitude, $vector);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(AiCall $call): array
    {
        $parts = [];

        foreach ($call->imageBlobs as $image) {
            /*
             * Images first, then the instruction — and the order is not cosmetic.
             *
             * With the text first, the model reads a brief and then happens to be shown a
             * picture, and it writes a new picture from the brief. With the image first it
             * reads "here is a thing" and then "do this to it", and it edits. The same
             * prompt, the same model, the same photograph: one comes back as the
             * customer's own living room with furniture in it, the other as a stranger's.
             *
             * Inline bytes rather than a link, too. `file_data.file_uri` accepts a URI from
             * Google's own Files API and nothing else — pointing it at one of our signed
             * URLs failed every call with "Cannot fetch content from the provided URL",
             * which the platform then showed a customer as a problem with their photograph.
             * And a room photograph's URL must not leave this system in any case.
             */
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]];
        }

        $parts[] = ['text' => $call->prompt];

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

        /*
         * The shape is both asked for and checked.
         *
         * Only the MIME type used to be sent, on the reasoning that the gateway validates
         * the shape itself so "valid" means the same thing whichever provider ran the call.
         * That reasoning still holds and the gateway is still the authority — but asking
         * for JSON and describing the shape only in prose left the model free to invent one.
         * The layout plan came back sometimes as `{category, wall, max_width_mm}` and
         * sometimes as a paragraph of interior-design advice, and the second kind produced
         * a design with an empty shopping list and a rendered room full of furniture nobody
         * sells. Handing Google the schema makes the first attempt usually right; the
         * gateway's own check makes a wrong one still fail here rather than downstream.
         */
        if ($call->expectsStructuredOutput() && $call->model->supports_structured_output) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';

            $schema = $this->googleSchema($call->responseSchema ?? []);

            if ($schema !== null) {
                $payload['generationConfig']['responseSchema'] = $schema;
            }
        }

        /*
         * An image answer comes back in the shape of the picture that was sent.
         *
         * Without this the model answers in its own default — a wide cinematic frame — and
         * a room photographed in portrait comes back as a wider room. Not a *cropped* room:
         * a different one, because the model fills the frame it was asked for. It was the
         * single biggest reason a customer's own living room came back as somebody else's.
         */
        if ($call->modality() === AiModality::Image) {
            $payload['generationConfig']['responseModalities'] = ['IMAGE'];

            $ratio = $this->aspectRatioOf($call);

            if ($ratio !== null) {
                $payload['generationConfig']['imageConfig'] = ['aspectRatio' => $ratio];
            }
        }

        return $payload;
    }

    /**
     * Our JSON Schema in the dialect Google accepts.
     *
     * Google takes an OpenAPI subset with upper-case type names and a short list of
     * keywords, so this translates rather than forwards. Anything it does not recognise is
     * dropped instead of passed through: a schema Google rejects fails the whole call with
     * an `invalid_request`, which would turn a validation aid into an outage. Whatever is
     * dropped here is still enforced by the gateway afterwards, so the loss is in how often
     * the model gets it right first time, never in what counts as right.
     *
     * Returns null when there is nothing worth sending, and the call goes out with the MIME
     * type alone, exactly as it did before.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function googleSchema(array $schema): ?array
    {
        if ($schema === []) {
            return null;
        }

        $translated = $this->translateSchema($schema, 'object');

        return $translated === null || ($translated['properties'] ?? []) === [] ? null : $translated;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function translateSchema(array $node, ?string $assumedType = null): ?array
    {
        $type = is_string($node['type'] ?? null) ? strtolower((string) $node['type']) : $assumedType;

        $googleType = match ($type) {
            'object' => 'OBJECT',
            'array' => 'ARRAY',
            'string' => 'STRING',
            'integer' => 'INTEGER',
            'number' => 'NUMBER',
            'boolean' => 'BOOLEAN',
            default => null,
        };

        if ($googleType === null) {
            return null;
        }

        $out = ['type' => $googleType];

        if ($googleType === 'ARRAY') {
            $items = $this->translateSchema((array) ($node['items'] ?? []));

            // An array of unspecified things. Google wants an item type, so this is one of
            // the cases where saying less is the only way to say anything at all.
            if ($items === null) {
                return null;
            }

            $out['items'] = $items;

            return $out;
        }

        if ($googleType !== 'OBJECT') {
            return $out;
        }

        $properties = [];

        foreach ((array) ($node['properties'] ?? []) as $key => $definition) {
            $child = $this->translateSchema((array) $definition);

            if ($child !== null) {
                $properties[(string) $key] = $child;
            }
        }

        if ($properties === []) {
            return null;
        }

        $out['properties'] = $properties;

        // Only the required fields that survived translation, so Google is never told to
        // insist on a property the schema it received does not describe.
        $required = array_values(array_filter(
            (array) ($node['required'] ?? []),
            static fn (mixed $key): bool => is_string($key) && isset($properties[$key]),
        ));

        if ($required !== []) {
            $out['required'] = $required;
        }

        return $out;
    }

    /**
     * The nearest aspect ratio the API offers to the picture we were given.
     *
     * Nearest rather than exact: the API takes a fixed set, and asking for 1.37:1 is an
     * error rather than a refinement. The first supplied image is the one that matters —
     * it is the room being edited; the rest are products.
     */
    private function aspectRatioOf(AiCall $call): ?string
    {
        /*
         * An explicit ratio wins, for a call with nothing to measure.
         *
         * Editing a room infers the frame from the photograph, which is the whole point.
         * Generating from a sentence has no photograph, and left to itself the model
         * answers in a wide cinematic frame — fine for a room, wrong for a product shot
         * that will sit in a square grid with its legs cropped off.
         */
        $requested = $call->options['aspect_ratio'] ?? null;

        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        $first = $call->imageBlobs[0] ?? null;

        if ($first === null || ($first['width'] ?? 0) <= 0 || ($first['height'] ?? 0) <= 0) {
            return null;
        }

        $ratio = $first['width'] / $first['height'];

        $supported = [
            '1:1' => 1.0,
            '3:4' => 0.75,
            '4:3' => 4 / 3,
            '9:16' => 0.5625,
            '16:9' => 16 / 9,
        ];

        $best = null;
        $bestDistance = INF;

        foreach ($supported as $name => $value) {
            $distance = abs($ratio - $value);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $name;
            }
        }

        return $best;
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

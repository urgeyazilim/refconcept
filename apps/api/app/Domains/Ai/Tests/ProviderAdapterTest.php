<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Providers\GoogleAiProvider;
use App\Domains\Ai\Providers\OpenAiProvider;
use App\Domains\Ai\Services\AiCall;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * What the adapters do with the answers real providers give.
 *
 * The HTTP layer is faked, which is the honest limit of this suite: it asserts that
 * *given* a response of a certain shape the adapter classifies it correctly, not that a
 * provider still produces that shape. The second is not knowable from a test, and a
 * recorded fixture pretending otherwise would only assert that it still matches itself.
 *
 * What is worth testing is exactly the part that is ours: the translation. Getting a
 * classification wrong is not a cosmetic error — it decides whether the gateway retries
 * something that will never work, or gives up on something that would have worked.
 */
beforeEach(function (): void {
    $this->provider = AiProvider::query()->create([
        'code' => 'test-provider',
        'name' => 'Test',
        'driver' => 'openai',
    ]);
});

/**
 * Builds a call against a model of the given modality.
 *
 * @param  array<string, mixed>|null  $schema
 */
function callFor(AiProvider $provider, AiModality $modality, AiTask $task, ?array $schema = null): AiCall
{
    $model = AiModel::query()->create([
        'provider_id' => $provider->getKey(),
        // Unique per call: a test that builds two models needs two rows, and one
        // shared code would make the second insert a constraint violation.
        'code' => 'test-model-'.uniqid(),
        'name' => 'Test model',
        'modality' => $modality,
        'max_output_tokens' => 500,
        'supports_structured_output' => true,
        'supports_image_input' => true,
    ]);

    $model->setRelation('provider', $provider);

    return new AiCall(
        task: $task,
        model: $model,
        prompt: 'Test istemi',
        responseSchema: $schema,
        apiKey: 'test-key',
    );
}

describe('OpenAI adapter', function (): void {
    it('reads an answer and its token counts', function (): void {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Merhaba'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ]),
        ]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        expect($result->successful)->toBeTrue()
            ->and($result->text)->toBe('Merhaba')
            ->and($result->inputTokens)->toBe(120)
            ->and($result->outputTokens)->toBe(30);
    });

    it('classifies a rate limit as something worth trying again', function (): void {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        expect($result->failureKind)->toBe(AiFailureKind::RateLimited)
            ->and($result->isRetryable())->toBeTrue();
    });

    it('classifies a bad request as something that will fail identically', function (): void {
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'Unknown model']], 400)]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        // Retrying it three times spends a customer's patience arriving at the same
        // place, and the fallback provider would reject it for the same reason.
        expect($result->failureKind)->toBe(AiFailureKind::InvalidRequest)
            ->and($result->isRetryable())->toBeFalse()
            ->and($result->warrantsFallback())->toBeFalse();
    });

    it('separates a safety refusal from an ordinary bad request', function (): void {
        // Both arrive as a 400 from this provider, and they mean opposite things: one
        // warrants trying a different provider, the other warrants stopping.
        Http::fake([
            '*/chat/completions' => Http::response(
                ['error' => ['message' => 'Your request was rejected as a result of our content policy.']],
                400,
            ),
        ]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        expect($result->failureKind)->toBe(AiFailureKind::SafetyRefusal)
            ->and($result->warrantsFallback())->toBeTrue();
    });

    it('catches a content filter that arrives inside a successful response', function (): void {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => null], 'finish_reason' => 'content_filter']],
                'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 0],
            ]),
        ]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        // A 200 that contains no answer is a failure, and reporting it as success would
        // hand an empty string to whatever reads it next.
        expect($result->successful)->toBeFalse()
            ->and($result->failureKind)->toBe(AiFailureKind::SafetyRefusal)
            ->and($result->inputTokens)->toBe(90);
    });

    it('re-hosts an image the provider returned inline', function (): void {
        Storage::fake('s3-public');
        config()->set('refconcept.storage.public_disk', 's3-public');

        Http::fake([
            '*/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode('fake-png-bytes')]],
            ]),
        ]);

        $result = app(OpenAiProvider::class)->execute(
            callFor($this->provider, AiModality::Image, AiTask::ImageRenderDraft),
        );

        /*
         * Stored rather than passed through. The URL form this provider offers expires
         * within the hour, and a design a customer opens next month must not depend on a
         * link that lived for one.
         */
        expect($result->successful)->toBeTrue()
            ->and($result->imageCount)->toBe(1)
            ->and(Storage::disk('s3-public')->allFiles())->toHaveCount(1);
    });

    it('refuses to call anything without a key', function (): void {
        Http::fake();

        $model = AiModel::query()->create([
            'provider_id' => $this->provider->getKey(),
            'code' => 'no-key',
            'name' => 'Anahtarsız',
            'modality' => AiModality::Text,
        ]);

        $model->setRelation('provider', $this->provider);

        $result = app(OpenAiProvider::class)->execute(new AiCall(
            task: AiTask::SupportAssist,
            model: $model,
            prompt: 'Test',
            apiKey: null,
        ));

        // Reported as a configuration problem rather than an authentication failure —
        // nothing was authenticated, because the call never left the building.
        expect($result->failureKind)->toBe(AiFailureKind::InvalidRequest);

        Http::assertNothingSent();
    });
});

describe('Google adapter', function (): void {
    it('sends the key as a header rather than in the query string', function (): void {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Merhaba']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 12],
        ])]);

        app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        /*
         * `?key=` works and is what most examples show. A query string is also the part
         * of a URL that ends up in access logs, error trackers and browser history; a
         * header is not.
         */
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('x-goog-api-key', 'test-key')
                && ! str_contains($request->url(), 'key=');
        });
    });

    it('treats a safety refusal that arrives as a 200 as a failure', function (): void {
        Http::fake(['*' => Http::response([
            'candidates' => [['finishReason' => 'SAFETY']],
            'usageMetadata' => ['promptTokenCount' => 55],
        ])]);

        $result = app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        /*
         * The trap this provider sets: a refusal is an ordinary successful response with
         * a finishReason. An adapter that checked only the status code would report an
         * empty answer as a success, and a customer would be told nothing at all.
         */
        expect($result->successful)->toBeFalse()
            ->and($result->failureKind)->toBe(AiFailureKind::SafetyRefusal)
            ->and($result->warrantsFallback())->toBeTrue()
            ->and($result->inputTokens)->toBe(55);
    });

    it('catches a prompt blocked before generation', function (): void {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

        $result = app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        // No candidates at all — a different shape from the one above, and just as silent.
        expect($result->failureKind)->toBe(AiFailureKind::SafetyRefusal);
    });

    it('stores an inline image and reports it as one', function (): void {
        Storage::fake('s3-public');
        config()->set('refconcept.storage.public_disk', 's3-public');

        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('bytes')]],
            ]], 'finishReason' => 'STOP']],
        ])]);

        $result = app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Image, AiTask::ImageRenderDraft),
        );

        expect($result->successful)->toBeTrue()
            ->and($result->imageCount)->toBe(1)
            ->and($result->imageUrls[0] ?? '')->toContain('ai-renders/');
    });

    it('reports an empty response as malformed rather than as an empty answer', function (): void {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => []], 'finishReason' => 'MAX_TOKENS']],
        ])]);

        $result = app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        expect($result->successful)->toBeFalse()
            ->and($result->failureKind)->toBe(AiFailureKind::MalformedOutput)
            // The reason is carried through, because "it stopped at MAX_TOKENS" is the
            // one sentence that tells an operator to raise a limit.
            ->and($result->failureMessage)->toContain('MAX_TOKENS');
    });

    it('classifies an overloaded model as retryable', function (): void {
        Http::fake(['*' => Http::response(['error' => ['message' => 'The model is overloaded.']], 503)]);

        $result = app(GoogleAiProvider::class)->execute(
            callFor($this->provider, AiModality::Text, AiTask::SupportAssist),
        );

        expect($result->failureKind)->toBe(AiFailureKind::ProviderError)
            ->and($result->isRetryable())->toBeTrue();
    });
});

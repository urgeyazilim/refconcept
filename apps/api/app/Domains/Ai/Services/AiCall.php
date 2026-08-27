<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Models\AiModel;

/**
 * One request, resolved and ready to send.
 *
 * Everything an adapter needs and nothing it does not: the prompt is already rendered,
 * the model already chosen, the schema already looked up. An adapter that had to
 * resolve a route or render a template would be a second place those decisions are
 * made, and the two would eventually disagree about which prompt version ran.
 *
 * Immutable. A call that could be mutated in flight is a call whose recorded request
 * might not be the one that was sent, which defeats the point of recording it.
 */
final readonly class AiCall
{
    /**
     * @param  array<int, string>  $imageUrls  where the images came from, for the record
     * @param  list<array{mime: string, data: string}>  $imageBlobs  the images themselves,
     *                                                               base64, read inside our own network — see InlineImageLoader for why a URL is
     *                                                               not handed to a provider
     * @param  array<string, mixed>|null  $responseSchema  the shape the answer must take
     * @param  array<string, mixed>  $options  provider-neutral knobs
     */
    public function __construct(
        public AiTask $task,
        public AiModel $model,
        public string $prompt,
        public ?string $systemPrompt = null,
        public array $imageUrls = [],
        public array $imageBlobs = [],
        public ?array $responseSchema = null,
        public int $temperatureBps = 7000,
        public int $timeoutSeconds = 60,
        public ?string $apiKey = null,
        public array $options = [],
    ) {}

    /** Whether the answer has to parse into {@see $responseSchema}. */
    public function expectsStructuredOutput(): bool
    {
        return $this->responseSchema !== null;
    }

    public function modality(): AiModality
    {
        return $this->task->modality();
    }

    /**
     * Temperature as the fraction providers actually take.
     *
     * Stored in basis points because it is configuration in a database, and a float
     * column for a value that is only ever set in hundredths invites the same rounding
     * conversations money has.
     */
    public function temperature(): float
    {
        return $this->temperatureBps / 10_000;
    }

    /**
     * A stable fingerprint of what is being asked.
     *
     * Used to make the fake provider deterministic and to spot a job being submitted
     * twice. Deliberately excludes the timeout and the API key: neither changes what
     * was asked, and a key must never end up in something that gets logged.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->task->value,
            $this->model->code,
            $this->systemPrompt,
            $this->prompt,
            $this->imageUrls,
            $this->responseSchema,
            $this->temperatureBps,
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }
}

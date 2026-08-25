<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Enums\AiFailureKind;

/**
 * What came back from one call.
 *
 * A failure is a value here rather than an exception, because the gateway has to
 * *reason* about it: a timeout is worth retrying, a safety refusal is worth falling
 * back to a different provider, and an invalid request is worth neither. An exception
 * would carry a message and throw away the classification that decides all three.
 *
 * Usage is reported by the adapter because only the adapter knows how its provider
 * counts tokens. Cost is not: that is looked up from the rate table by the gateway, so
 * a provider cannot misreport what RefConcept believes it spent.
 */
final readonly class AiResult
{
    /**
     * @param  array<string, mixed>|null  $structured  the parsed answer, for schema tasks
     * @param  array<int, string>  $imageUrls  images the provider produced, by URL
     * @param  array<int, string>  $imageRefs  images already stashed on the private disk
     * @param  array<int, float>|null  $embedding  a vector, for embedding tasks
     */
    private function __construct(
        public bool $successful,
        public ?string $text = null,
        public ?array $structured = null,
        public array $imageUrls = [],
        public array $imageRefs = [],
        public ?array $embedding = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $imageCount = 0,
        public ?AiFailureKind $failureKind = null,
        public ?string $failureMessage = null,
        public ?int $httpStatus = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<int, string>  $imageUrls
     * @param  array<int, string>  $imageRefs
     * @param  array<int, float>|null  $embedding
     */
    public static function success(
        ?string $text = null,
        ?array $structured = null,
        array $imageUrls = [],
        array $imageRefs = [],
        ?array $embedding = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $imageCount = 0,
        ?int $httpStatus = 200,
    ): self {
        return new self(
            successful: true,
            text: $text,
            structured: $structured,
            imageUrls: $imageUrls,
            imageRefs: $imageRefs,
            embedding: $embedding,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            imageCount: $imageCount,
            httpStatus: $httpStatus,
        );
    }

    /**
     * A failure, with the tokens it consumed anyway.
     *
     * A provider that answered and then refused still charges for the input, and a
     * cost report that ignores failed calls understates the bill by exactly the amount
     * somebody is about to be surprised by.
     */
    public static function failure(
        AiFailureKind $kind,
        string $message,
        ?int $httpStatus = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): self {
        return new self(
            successful: false,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            failureKind: $kind,
            failureMessage: $message,
            httpStatus: $httpStatus,
        );
    }

    public function isRetryable(): bool
    {
        return ! $this->successful && $this->failureKind?->isRetryable() === true;
    }

    public function warrantsFallback(): bool
    {
        return ! $this->successful && $this->failureKind?->warrantsFallback() === true;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}

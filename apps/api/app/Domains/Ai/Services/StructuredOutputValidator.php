<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Enums\AiFailureKind;

/**
 * Checks that an answer is the shape the application asked for.
 *
 * A deliberately small subset of JSON Schema: required keys, types, and nested
 * objects and arrays. Not a general validator, because a general validator invites a
 * schema nobody can read and failures nobody can explain to a customer. What is
 * checked is what the code downstream actually reads.
 *
 * Two failures are treated differently and both matter:
 *
 *  - **The answer is not JSON at all.** The commonest real failure with structured
 *    output: a model that helpfully explains itself in prose. Classified as malformed,
 *    which is retryable, because the same model usually complies on a second attempt.
 *  - **The answer is JSON but the wrong shape.** A key the next step reads is missing.
 *    Same classification, same reasoning, and far better caught here than two steps
 *    downstream where the symptom is an empty room.
 */
final class StructuredOutputValidator
{
    /**
     * Returns the result unchanged if it validates, or a malformed-output failure.
     *
     * @param  array<string, mixed>  $schema
     */
    public function validate(AiResult $result, array $schema): AiResult
    {
        $structured = $result->structured ?? $this->decode($result->text);

        if ($structured === null) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'Model, beklenen JSON yapısı yerine düz metin döndürdü.',
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
            );
        }

        // An empty schema means "must be an object, contents unchecked" — the state a
        // task is in before somebody has written its schema down.
        if ($schema === []) {
            return AiResult::success(
                text: $result->text,
                structured: $structured,
                imageUrls: $result->imageUrls,
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
                imageCount: $result->imageCount,
                httpStatus: $result->httpStatus,
            );
        }

        $problems = $this->check($structured, $schema, '');

        if ($problems !== []) {
            return AiResult::failure(
                AiFailureKind::MalformedOutput,
                'Yanıt beklenen yapıya uymuyor: '.implode('; ', array_slice($problems, 0, 5)),
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
            );
        }

        return AiResult::success(
            text: $result->text,
            structured: $structured,
            imageUrls: $result->imageUrls,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            imageCount: $result->imageCount,
            httpStatus: $result->httpStatus,
        );
    }

    /**
     * Parses the answer, tolerating the wrappers models actually produce.
     *
     * A fenced code block is not malformed output in any sense a customer would
     * recognise — the model answered correctly and added decoration — so it is
     * unwrapped rather than rejected.
     *
     * @return array<string, mixed>|null
     */
    private function decode(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $candidate = trim($text);

        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $candidate) ?? $candidate;
        }

        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function check(array $value, array $schema, string $path): array
    {
        $problems = [];

        /** @var array<int, string> $required */
        $required = (array) ($schema['required'] ?? []);

        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                $problems[] = sprintf('"%s%s" alanı eksik', $path, $key);
            }
        }

        /** @var array<string, mixed> $properties */
        $properties = (array) ($schema['properties'] ?? []);

        foreach ($properties as $key => $definition) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $problems = [...$problems, ...$this->checkValue(
                $value[$key],
                (array) $definition,
                $path.$key,
            )];
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    private function checkValue(mixed $value, array $definition, string $path): array
    {
        $type = $definition['type'] ?? null;

        if ($type === null) {
            return [];
        }

        if (! $this->matchesType($value, (string) $type)) {
            return [sprintf('"%s" %s olmalı', $path, $type)];
        }

        if ($type === 'object' && is_array($value)) {
            return $this->check($value, $definition, $path.'.');
        }

        if ($type === 'array' && is_array($value) && isset($definition['items'])) {
            $problems = [];

            foreach ($value as $index => $item) {
                $problems = [...$problems, ...$this->checkValue(
                    $item,
                    (array) $definition['items'],
                    $path.'['.$index.']',
                )];
            }

            return $problems;
        }

        return [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // JSON has one number type; a model returning 3 where 3.0 was expected is
            // right, and rejecting it would be pedantry with a customer-visible cost.
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }
}

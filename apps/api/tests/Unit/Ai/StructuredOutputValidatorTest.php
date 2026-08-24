<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Ai\Services\StructuredOutputValidator;

/**
 * The check that stands between a model's answer and the code that reads it.
 *
 * Its job is narrow and its failures are the ones that matter: an answer that is not
 * JSON, and an answer that is JSON but missing a key something downstream will read.
 * Both are caught here rather than two steps later, where the symptom is an empty room
 * and the cause is three services away.
 */
beforeEach(function (): void {
    $this->validator = new StructuredOutputValidator;
});

it('accepts an answer that matches the schema', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: '{"room_type":"salon","confidence":0.9}'),
        [
            'required' => ['room_type'],
            'properties' => [
                'room_type' => ['type' => 'string'],
                'confidence' => ['type' => 'number'],
            ],
        ],
    );

    expect($result->successful)->toBeTrue()
        // Parsed once, here, so nothing downstream decodes it a second time.
        ->and($result->structured)->toBe(['room_type' => 'salon', 'confidence' => 0.9]);
});

it('unwraps a fenced code block rather than calling it malformed', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: "```json\n{\"room_type\":\"salon\"}\n```"),
        ['required' => ['room_type']],
    );

    /*
     * A model that answers correctly and adds decoration has not failed in any sense a
     * customer would recognise. Rejecting this would burn a retry to get the same answer
     * without the backticks.
     */
    expect($result->successful)->toBeTrue()
        ->and($result->structured)->toBe(['room_type' => 'salon']);
});

it('rejects prose where an object was asked for', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: 'Elbette! Odanız için önerim şu: geniş bir kanepe…', inputTokens: 400),
        ['required' => ['room_type']],
    );

    expect($result->successful)->toBeFalse()
        ->and($result->failureKind)->toBe(AiFailureKind::MalformedOutput)
        // Retryable: the same model usually complies on a second attempt, and failing a
        // customer's design over one bad sample would be the wrong call.
        ->and($result->isRetryable())->toBeTrue()
        // The tokens it burned are still reported. A provider that read the input and
        // then rambled still charges for reading it.
        ->and($result->inputTokens)->toBe(400);
});

it('names the missing key', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: '{"style":"modern"}'),
        ['required' => ['placements']],
    );

    expect($result->successful)->toBeFalse()
        // The person reading this failure should not have to diff two JSON blobs.
        ->and($result->failureMessage)->toContain('placements');
});

it('rejects a value of the wrong type', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: '{"total_minor":"48900.00"}'),
        [
            'required' => ['total_minor'],
            'properties' => ['total_minor' => ['type' => 'integer']],
        ],
    );

    /*
     * Exactly the failure worth catching for money: a model that answers with a decimal
     * string where minor units were asked for, which would otherwise be cast to an int
     * somewhere downstream and quietly lose the kuruş.
     */
    expect($result->successful)->toBeFalse()
        ->and($result->failureMessage)->toContain('total_minor');
});

it('accepts an integer where a number was asked for', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: '{"confidence":1}'),
        ['properties' => ['confidence' => ['type' => 'number']]],
    );

    // JSON has one number type. Rejecting 1 where 1.0 was expected would be pedantry
    // with a customer-visible cost.
    expect($result->successful)->toBeTrue();
});

it('checks inside nested objects and arrays', function (): void {
    $schema = [
        'required' => ['surfaces', 'objects'],
        'properties' => [
            'surfaces' => [
                'type' => 'object',
                'required' => ['floor'],
                'properties' => ['floor' => ['type' => 'object']],
            ],
            'objects' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'required' => ['label']],
            ],
        ],
    ];

    $good = $this->validator->validate(
        AiResult::success(text: '{"surfaces":{"floor":{"material":"ahşap"}},"objects":[{"label":"kanepe"}]}'),
        $schema,
    );

    $bad = $this->validator->validate(
        AiResult::success(text: '{"surfaces":{"floor":{}},"objects":[{"confidence":0.9}]}'),
        $schema,
    );

    expect($good->successful)->toBeTrue()
        ->and($bad->successful)->toBeFalse()
        // The path says which element, not merely that something somewhere is wrong.
        ->and($bad->failureMessage)->toContain('objects[0]');
});

it('treats an empty schema as "must be an object"', function (): void {
    // The state a task is in before anybody has written its schema down. Requiring JSON
    // is still meaningful; requiring particular keys is not yet.
    expect($this->validator->validate(AiResult::success(text: '{"anything":true}'), [])->successful)->toBeTrue()
        ->and($this->validator->validate(AiResult::success(text: 'düz metin'), [])->successful)->toBeFalse();
});

it('does not re-parse an answer a provider already structured', function (): void {
    $result = $this->validator->validate(
        AiResult::success(text: 'bu metin okunmamalı', structured: ['room_type' => 'salon']),
        ['required' => ['room_type']],
    );

    expect($result->successful)->toBeTrue()
        ->and($result->structured)->toBe(['room_type' => 'salon']);
});

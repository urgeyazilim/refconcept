<?php

declare(strict_types=1);

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Ai\Services\StructuredOutputValidator;

/**
 * What counts as a well-shaped answer.
 *
 * The gateway validates every structured answer here rather than in each adapter, so
 * "valid" means one thing whichever provider ran the call. Two rules were wrong in ways
 * that pulled in opposite directions, and between them they broke design generation: the
 * check was too lax about what a plan had to contain, and too strict about how a model was
 * allowed to say "nothing here".
 */
/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $schema
 */
function validating(array $payload, array $schema): AiResult
{
    return app(StructuredOutputValidator::class)->validate(
        AiResult::success(structured: $payload),
        $schema,
    );
}

it('accepts an optional field written out as null', function (): void {
    /*
     * Models fill in the whole shape they were shown and put null where they have nothing
     * to say. `"wall": null` for a rug in the middle of a room is a correct answer, and
     * refusing it as "wall must be a string" burned every retry on a plan that was right —
     * the customer was told their design could not be prepared.
     */
    $result = validating(
        ['style' => 'modern', 'placements' => [['category' => 'hali', 'max_width_mm' => 3000, 'wall' => null]]],
        [
            'required' => ['style', 'placements'],
            'properties' => [
                'style' => ['type' => 'string'],
                'placements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['category', 'max_width_mm'],
                        'properties' => [
                            'category' => ['type' => 'string'],
                            'max_width_mm' => ['type' => 'integer'],
                            'wall' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
    );

    expect($result->successful)->toBeTrue();
});

it('treats a required field written out as null as missing', function (): void {
    // The other direction. A required category that came back null is exactly as unusable
    // to the product search as one that was never written, and "the key is there" is not
    // the promise the schema makes.
    $result = validating(
        ['category' => null],
        ['required' => ['category'], 'properties' => ['category' => ['type' => 'string']]],
    );

    expect($result->successful)->toBeFalse()
        ->and($result->failureKind)->toBe(AiFailureKind::MalformedOutput)
        ->and($result->failureMessage)->toContain('category');
});

it('refuses a layout plan whose placements are prose', function (): void {
    /*
     * The shape that started it. This validates against `placements: {type: array}` and is
     * useless to everything downstream — no category means no product search, no products
     * means no reference photographs, and the renderer draws furniture out of its own head.
     */
    $result = validating(
        ['style' => 'modern', 'placements' => [[
            'name' => 'L Köşe Koltuk',
            'position_description' => 'TV ünitesinin karşısına yerleştirilir.',
        ]]],
        [
            'required' => ['style', 'placements'],
            'properties' => [
                'style' => ['type' => 'string'],
                'placements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['category', 'max_width_mm'],
                        'properties' => ['category' => ['type' => 'string'], 'max_width_mm' => ['type' => 'integer']],
                    ],
                ],
            ],
        ],
    );

    expect($result->successful)->toBeFalse()
        // Retryable on purpose: a model that free-formed once usually complies next time,
        // and the alternative is failing a customer's design over one bad sample.
        ->and($result->isRetryable())->toBeTrue();
});

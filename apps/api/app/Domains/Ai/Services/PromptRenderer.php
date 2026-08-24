<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\PromptVersion;

/**
 * Turns a job's input into the text a model is actually sent.
 *
 * Kept out of the gateway because it is the one part of the pipeline somebody
 * non-technical will want to reason about — "what exactly did we ask it" — and out of
 * the adapters because the answer must be identical whichever provider runs it. Two
 * providers rendering the same job differently would make an A/B comparison between
 * them meaningless.
 *
 * A job with no prompt version falls back to its own input. That is not a nicety: a
 * task like a search-query rewrite has nothing to template, and forcing a prompt
 * version onto it would be ceremony with no content.
 */
final class PromptRenderer
{
    /**
     * @return array{prompt: string, system: string|null}
     */
    public function render(AiJob $job, ?PromptVersion $version): array
    {
        $variables = $this->variablesFrom($job);

        if ($version === null) {
            return [
                'prompt' => (string) ($job->input['prompt'] ?? ''),
                'system' => null,
            ];
        }

        return [
            'prompt' => $version->render($variables),
            'system' => $version->system_prompt,
        ];
    }

    /**
     * Which of a job's inputs may be substituted into a template.
     *
     * Scalars only, and image URLs deliberately excluded — those travel as attachments
     * rather than as text, and a URL pasted into a prompt is a URL a model may repeat
     * back into an answer somebody else reads.
     *
     * @return array<string, string|int|float|null>
     */
    private function variablesFrom(AiJob $job): array
    {
        $variables = [];

        foreach ($job->input as $key => $value) {
            if ($key === 'image_urls') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $variables[(string) $key] = $value;
            }

            /*
             * Nested structures are flattened to JSON rather than dropped: a design
             * plan wants the room's constraints in the prompt, and a model reads JSON
             * perfectly well. Dropping them silently would produce a prompt that looks
             * complete and asks for less than it should.
             */
            if (is_array($value)) {
                $variables[(string) $key] = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

        return $variables;
    }

    /**
     * Variables a template asks for that the job does not supply.
     *
     * Used by the admin screen when a prompt version is published, so a typo in a
     * placeholder is caught by a person rather than discovered as a slightly worse
     * answer nobody can account for.
     *
     * @param  array<string, mixed>  $sampleInput
     * @return array<int, string>
     */
    public function missingVariables(PromptVersion $version, array $sampleInput): array
    {
        return array_values(array_diff(
            $version->placeholders(),
            array_keys($sampleInput),
        ));
    }
}

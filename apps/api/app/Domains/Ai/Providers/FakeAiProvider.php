<?php

declare(strict_types=1);

namespace App\Domains\Ai\Providers;

use App\Domains\Ai\Contracts\AiProvider;
use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiModality;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\AiResult;
use App\Domains\Ai\Services\GeneratedImageStore;

/**
 * A model provider that never calls anything.
 *
 * The most important class in this domain, and the reason continuous integration can
 * exercise the whole AI path on every commit without spending a lira. Real providers
 * are non-deterministic by design: the same prompt produces different words, different
 * token counts and occasionally a refusal, and a test suite built on that is a test
 * suite that fails for reasons nobody can reproduce.
 *
 * Two modes:
 *
 *  - **Deterministic by default.** The answer is derived from the call's fingerprint,
 *    so the same request always produces the same reply, and a structured task gets a
 *    plausible object that satisfies its schema.
 *  - **Scripted, on demand.** {@see script()} queues specific outcomes — a timeout, a
 *    malformed answer, a refusal — so the gateway's retry, fallback and cost-cap paths
 *    can be tested against the failures they exist for rather than hoped about.
 *
 * The scripted queue is static because the gateway resolves adapters through the
 * container and a test cannot reach the instance it will be handed. {@see reset()} is
 * called between tests.
 */
final class FakeAiProvider implements AiProvider
{
    /**
     * Queued outcomes, consumed in order.
     *
     * @var array<int, AiResult>
     */
    private static array $scripted = [];

    /** How long a scripted "timeout" pretends to take, in milliseconds. */
    public static int $simulatedLatencyMs = 0;

    /** @var array<int, AiCall> */
    private static array $calls = [];

    public function driver(): string
    {
        return 'fake';
    }

    public function supports(AiCall $call): bool
    {
        // The fake serves everything on purpose: a test about routing should fail on
        // the routing, not on the fake declining to play a part.
        return true;
    }

    public function execute(AiCall $call): AiResult
    {
        self::$calls[] = $call;

        if (self::$scripted !== []) {
            return array_shift(self::$scripted);
        }

        return $call->expectsStructuredOutput()
            ? $this->structuredAnswer($call)
            : $this->plainAnswer($call);
    }

    // --- test control --------------------------------------------------------

    /** Queues outcomes for the next calls, in order. */
    public static function script(AiResult ...$results): void
    {
        self::$scripted = [...self::$scripted, ...$results];
    }

    public static function scriptFailure(AiFailureKind $kind, string $message = 'Simulated failure.'): void
    {
        self::script(AiResult::failure($kind, $message));
    }

    /**
     * Queues an answer that is not the JSON it was asked for.
     *
     * The single most common real failure with structured output, and the one most
     * worth having a test for: a model that helpfully explains itself in prose instead
     * of returning an object.
     */
    public static function scriptMalformed(): void
    {
        self::script(AiResult::success(
            text: 'Elbette! İşte odanız için düşündüklerim: geniş bir kanepe…',
            inputTokens: 400,
            outputTokens: 120,
        ));
    }

    public static function reset(): void
    {
        self::$scripted = [];
        self::$calls = [];
        self::$simulatedLatencyMs = 0;
    }

    /** @return array<int, AiCall> */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function lastCall(): ?AiCall
    {
        return self::$calls === [] ? null : self::$calls[array_key_last(self::$calls)];
    }

    public static function callCount(): int
    {
        return count(self::$calls);
    }

    // --- deterministic answers ------------------------------------------------

    private function plainAnswer(AiCall $call): AiResult
    {
        if ($call->modality() === AiModality::Image) {
            /*
             * A real image, written to the real store.
             *
             * It would be simpler to return a made-up URL, and it would also stop the
             * fake being usable for anything past the gateway: the design pipeline
             * *downloads* what a provider returns, and a URL that resolves to nothing
             * would fail every end-to-end run for a reason that has nothing to do with
             * the code under test. So the fake produces a genuine PNG — a flat colour
             * derived from the fingerprint, so the same request yields the same picture.
             */
            return AiResult::success(
                imageRefs: [$this->storedImageFor($call)],
                inputTokens: $this->tokensFor($call->prompt),
                imageCount: 1,
            );
        }

        return AiResult::success(
            text: 'Fake answer for '.$call->task->value.' ('.substr($call->fingerprint(), 0, 12).')',
            inputTokens: $this->tokensFor($call->prompt),
            outputTokens: 64,
        );
    }

    /**
     * A plausible object for a task that expects one.
     *
     * Shaped per task rather than generically, because the code downstream reads
     * specific keys: a room analysis whose `fixed_elements` is missing would make the
     * design planner's tests pass for the wrong reason.
     */
    private function structuredAnswer(AiCall $call): AiResult
    {
        $structured = match ($call->task) {
            AiTask::RoomAnalysis => [
                'room_type' => 'living_room',
                'confidence' => 0.94,
                'style' => ['modern'],
                'dominant_colors' => ['warm_white', 'oak'],
                'fixed_elements' => [
                    ['type' => 'window', 'preserve' => true],
                    ['type' => 'radiator', 'preserve' => true],
                ],
                'movable_objects' => [
                    ['type' => 'sofa', 'condition' => 'good'],
                ],
                'surfaces' => [
                    'floor' => ['material' => 'wood', 'change_allowed' => false],
                    'walls' => ['material' => 'plaster', 'change_allowed' => true],
                ],
                'measurement_quality' => 'estimated',
                'warnings' => [],
            ],

            AiTask::DesignPlan => [
                'style' => 'modern',
                'palette' => ['warm_white', 'oak', 'sand'],
                'placements' => [
                    ['category' => 'kanepe', 'wall' => 'south', 'max_width_mm' => 2200],
                    ['category' => 'sehpa', 'wall' => null, 'max_width_mm' => 900],
                ],
                'notes' => 'Pencere önü boş bırakıldı.',
            ],

            AiTask::ObjectExtraction => [
                'objects' => [
                    ['label' => 'kanepe', 'bbox' => [0.12, 0.44, 0.61, 0.82], 'confidence' => 0.91],
                    ['label' => 'sehpa', 'bbox' => [0.40, 0.66, 0.58, 0.80], 'confidence' => 0.84],
                ],
            ],

            AiTask::ProductTagging => [
                'tags' => ['modern', 'boucle', 'cream'],
                'color' => 'cream',
                'material' => 'boucle',
            ],

            AiTask::ProductMatchRerank => [
                'ranking' => [
                    ['candidate' => 0, 'score' => 0.92],
                    ['candidate' => 1, 'score' => 0.71],
                ],
            ],

            AiTask::BudgetOptimize => [
                'within_budget' => true,
                'total_minor' => 4_890_000,
                'substitutions' => [],
            ],

            default => ['result' => 'ok', 'fingerprint' => substr($call->fingerprint(), 0, 16)],
        };

        return AiResult::success(
            text: json_encode($structured, JSON_UNESCAPED_UNICODE) ?: '{}',
            structured: $structured,
            inputTokens: $this->tokensFor($call->prompt),
            outputTokens: 180,
        );
    }

    /**
     * A stand-in for a tokeniser.
     *
     * Roughly four characters per token, which is the usual approximation for Latin
     * text. Only ever used by the fake, so its inaccuracy costs nothing — but it has
     * to *vary with the prompt*, or a cost-cap test would pass whatever the input.
     */
    /**
     * Writes a small flat-colour PNG and returns its URL.
     *
     * Deterministic: the colour comes from the call fingerprint, so the same request
     * produces the same bytes. Sixteen pixels square, because nothing looks at it — what
     * matters is that it is a real image at a real address that a downloader can fetch.
     */
    private function storedImageFor(AiCall $call): string
    {
        $seed = $call->fingerprint();

        $image = imagecreatetruecolor(16, 16);

        if ($image === false) {
            // GD absent. A single transparent pixel rather than a fatal error, because a
            // test environment without the extension still needs the pipeline to run.
            return app(GeneratedImageStore::class)->stashBase64(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            ) ?? '';
        }

        try {
            $colour = imagecolorallocate($image,
                (int) hexdec(substr($seed, 0, 2)),
                (int) hexdec(substr($seed, 2, 2)),
                (int) hexdec(substr($seed, 4, 2)),
            );

            imagefilledrectangle($image, 0, 0, 16, 16, $colour === false ? 0 : $colour);

            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        return app(GeneratedImageStore::class)->stash($bytes, 'image/png');
    }

    private function tokensFor(string $prompt): int
    {
        return max(1, (int) ceil(mb_strlen($prompt) / 4));
    }
}

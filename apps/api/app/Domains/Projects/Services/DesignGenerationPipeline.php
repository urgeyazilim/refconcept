<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Matching\Services\ShoppingListBuilder;
use App\Domains\Projects\Enums\GenerationStage;
use App\Domains\Projects\Enums\RenderQuality;
use App\Domains\Projects\Exceptions\DesignGenerationFailed;
use App\Domains\Projects\Models\DesignPlan;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\DesignVersionEvent;
use App\Domains\Projects\Models\Room;
use App\Domains\Projects\Models\RoomAnalysis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a pending design version into an image.
 *
 * Three model calls in sequence — read the room, decide the layout, draw it — plus the
 * arithmetic in between that stops the layout asking for furniture the room cannot take.
 *
 * Two decisions shape everything here.
 *
 * **A customer pays for a design, not for the steps inside one.** The credits are held
 * once when the version is created and settled once when it finishes; the three AI jobs
 * underneath run at zero customer cost. Charging per step would mean somebody paying for
 * an analysis and a plan and then getting nothing because the render failed, which is
 * indefensible however defensible each individual charge looks.
 *
 * **Every step announces itself before it starts.** A render takes the better part of a
 * minute and a spinner that says nothing is indistinguishable from one that has hung. The
 * events are also what turns "it is slow" into "it is slow at the render step".
 *
 * Nothing here throws for a provider problem. The version carries the outcome, and a
 * caller that wanted an exception can ask it — because the caller is a queue worker, and
 * a queue worker that has to catch four exception types to decide whether to retry is one
 * that will get a type wrong.
 */
final class DesignGenerationPipeline
{
    public function __construct(
        private readonly AiJobDispatcher $dispatcher,
        private readonly DesignVersionTree $tree,
        private readonly RoomAnalyser $analyser,
        private readonly PlacementValidator $validator,
        private readonly RoomPhotoStorage $storage,
        private readonly CreditLedger $ledger,
        private readonly ShoppingListBuilder $shoppingList,
    ) {}

    /**
     * Runs a version to completion, or to a failure it can explain.
     */
    public function run(DesignVersion $version): DesignVersion
    {
        $version->loadMissing('design.room.project');

        $room = $version->design?->room;

        if ($room === null) {
            return $this->fail($version, DesignGenerationFailed::roomHasNoPhotograph());
        }

        $this->tree->markGenerating($version);
        $this->event($version, GenerationStage::Queued, 'succeeded', 'İşlem başladı.');

        try {
            $analysis = $this->analyse($version, $room);
            $plan = $this->plan($version, $room, $analysis);
            $this->render($version, $room, $analysis, $plan);
            $this->match($version);
        } catch (DesignGenerationFailed $e) {
            return $this->fail($version, $e);
        } catch (AiJobRefused $e) {
            /*
             * The gateway turned the request away before contacting anybody: the task is
             * paused, or nothing is routed to it. Its message is already written for a
             * customer — "Bu özellik şu anda kullanılamıyor" — and passing it through is
             * far better than the generic crash message, which would tell somebody their
             * render broke when in fact an operator switched the feature off.
             */
            return $this->fail($version, DesignGenerationFailed::unavailable($e->getMessage()));
        } catch (Throwable $e) {
            /*
             * An unexpected error is still a customer watching a spinner, so it is caught
             * and turned into a failure rather than left to the queue's error handler.
             * The customer gets a sentence they can act on; the detail goes to the log,
             * where it is of use to somebody.
             */
            Log::error('Design generation crashed', [
                'design_version_id' => $version->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return $this->fail($version, DesignGenerationFailed::renderFailed('beklenmeyen bir hata'));
        }

        $ready = $this->tree->markReady($version->fresh() ?? $version);

        $this->event($ready, GenerationStage::Done, 'succeeded', 'Tasarım hazır.');
        $this->settle($ready, successful: true);

        return $ready;
    }

    /**
     * What a version of this quality costs, in credits.
     *
     * Read from the routes rather than hard-coded, so an operator who repoints a task at a
     * cheaper model can reprice it without a deploy — which is the entire reason the
     * routing tables exist. Summed across the steps the pipeline will actually run.
     */
    public function costOf(RenderQuality $quality, bool $needsAnalysis = true): int
    {
        $tasks = array_filter([
            $needsAnalysis ? AiTask::RoomAnalysis : null,
            AiTask::DesignPlan,
            $quality->task(),
        ]);

        $costs = AiTaskRoute::query()
            ->whereIn('task', array_map(static fn (AiTask $task): string => $task->value, $tasks))
            ->pluck('credit_cost');

        return (int) $costs->sum();
    }

    // --- steps ---------------------------------------------------------------

    private function analyse(DesignVersion $version, Room $room): RoomAnalysis
    {
        $started = microtime(true);

        $existing = RoomAnalysis::query()
            ->where('room_id', $room->getKey())
            ->current()
            ->first();

        if ($existing !== null) {
            /*
             * Recorded as skipped rather than silently omitted. A customer comparing two
             * renders of the same room should be able to see why the second was quicker,
             * and an operator reading a slow pipeline should be able to see which steps
             * actually ran.
             */
            $this->event($version, GenerationStage::Analysis, 'skipped', 'Oda daha önce incelenmişti.');

            return $existing;
        }

        $this->event($version, GenerationStage::Analysis, 'started', 'Oda fotoğrafı inceleniyor…');

        $analysis = $this->analyser->forRoom($room);

        $this->event(
            $version,
            GenerationStage::Analysis,
            'succeeded',
            'Oda incelendi.',
            $this->elapsed($started),
        );

        return $analysis;
    }

    private function plan(DesignVersion $version, Room $room, RoomAnalysis $analysis): DesignPlan
    {
        $started = microtime(true);

        $this->event($version, GenerationStage::Plan, 'started', 'Yerleşim planlanıyor…');

        $ran = $this->dispatcher->runInline(
            task: AiTask::DesignPlan,
            input: [
                'analysis' => $analysis->payload,
                'constraints' => $this->constraintsFor($room),
                'budget_minor' => $room->project?->budget_minor,
                'style' => $version->style_prompt ?? $version->style_code,
                'prompt' => $version->user_prompt,
            ],
            subject: $version,
            creditCostOverride: 0,
        );

        if ($ran->status !== AiJobStatus::Succeeded) {
            throw DesignGenerationFailed::planFailed($this->reasonFor($ran));
        }

        /** @var array<string, mixed> $structured */
        $structured = (array) ($ran->output['structured'] ?? []);

        /** @var array<int, mixed> $proposed */
        $proposed = (array) ($structured['placements'] ?? []);

        /*
         * The arithmetic the model is bad at. It will happily put a 2600mm sofa against a
         * 2200mm wall — the render will look fine, because an image is not to scale, and
         * the customer discovers the problem when a delivery van arrives.
         */
        $checked = $this->validator->check($room, $proposed);

        if ($checked['accepted'] === [] && $proposed !== []) {
            throw DesignGenerationFailed::planHadNothingUsable();
        }

        $plan = DesignPlan::query()->create([
            'design_version_id' => $version->getKey(),
            'ai_job_id' => $ran->getKey(),
            'room_analysis_id' => $analysis->getKey(),
            'style' => $this->stringOrNull($structured['style'] ?? null),
            'palette' => is_array($structured['palette'] ?? null) ? $structured['palette'] : null,
            'placements' => $checked['accepted'],
            'notes' => $this->stringOrNull($structured['notes'] ?? null),
            // Kept rather than dropped: a plan that quietly loses a piece of furniture
            // produces an image with a sideboard and a shopping list without one.
            'rejected' => $checked['rejected'] === [] ? null : $checked['rejected'],
        ]);

        $this->event(
            $version,
            GenerationStage::Plan,
            'succeeded',
            $checked['rejected'] === []
                ? 'Yerleşim planı hazır.'
                : sprintf('Yerleşim planı hazır; %d öneri odaya sığmadığı için çıkarıldı.', count($checked['rejected'])),
            $this->elapsed($started),
        );

        return $plan;
    }

    private function render(DesignVersion $version, Room $room, RoomAnalysis $analysis, DesignPlan $plan): void
    {
        $started = microtime(true);

        $quality = $version->render_quality;

        $this->event($version, GenerationStage::Render, 'started', $quality->label().' üretiliyor…');

        $ran = $this->dispatcher->runInline(
            task: $quality->task(),
            input: [
                'room_type' => $analysis->detected_room_type ?? $room->room_type->value,
                'style' => $plan->style ?? $version->style_code,
                'plan' => $plan->placements,
                'palette' => $plan->palette,
                /*
                 * Named explicitly in the prompt rather than left to the model to infer
                 * from the photograph. "Keep the window" is a sentence a model follows;
                 * "look at the picture and work out what not to change" is not.
                 */
                'preserve' => $analysis->preservedElements(),
                'instruction' => $version->user_prompt,
            ],
            subject: $version,
            creditCostOverride: 0,
        );

        if ($ran->status !== AiJobStatus::Succeeded) {
            throw DesignGenerationFailed::renderFailed($this->reasonFor($ran));
        }

        /** @var array<int, string> $refs */
        $refs = (array) ($ran->output['image_refs'] ?? []);

        /** @var array<int, string> $urls */
        $urls = (array) ($ran->output['image_urls'] ?? []);

        if ($refs === [] && $urls === []) {
            // The call succeeded, the money was spent, and there is nothing to save. A
            // different problem from a provider failure, and worth its own message.
            throw DesignGenerationFailed::renderProducedNoImage();
        }

        $this->event($version, GenerationStage::Save, 'started', 'Görsel kaydediliyor…');

        try {
            /*
             * A reference is preferred over a URL and there is nothing subtle about why:
             * the adapter already wrote the bytes to the private disk, so this is a copy
             * within one filesystem rather than an HTTP round trip to fetch our own file.
             * Only a provider that hands back a link — and whose link expires — makes us
             * go over the network for it.
             */
            if ($refs !== []) {
                $this->storage->storeRenderFromRef((string) $version->getKey(), $refs[0]);
            } else {
                $this->storage->storeRenderFromUrl((string) $version->getKey(), $urls[0]);
            }
        } catch (Throwable $e) {
            throw DesignGenerationFailed::renderCouldNotBeSaved($e->getMessage());
        }

        $version->forceFill(['ai_job_id' => $ran->getKey()])->save();

        $this->event(
            $version,
            GenerationStage::Render,
            'succeeded',
            'Görsel üretildi.',
            $this->elapsed($started),
        );
    }

    /**
     * Turns the plan into a shopping list.
     *
     * Deliberately after the render and deliberately unable to fail the version. A design
     * with an image and no product suggestions is still a design the customer wanted; one
     * that failed because the catalogue had no sofas in their budget would be a render
     * they paid for and cannot see. The list is rebuildable on demand, so a bad moment
     * here costs nothing permanent.
     */
    private function match(DesignVersion $version): void
    {
        $started = microtime(true);

        $this->event($version, GenerationStage::Match, 'started', 'Ürünler eşleştiriliyor…');

        try {
            $matches = $this->shoppingList->build($version->fresh() ?? $version);
        } catch (Throwable $e) {
            Log::warning('Shopping list could not be built', [
                'design_version_id' => $version->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $this->event(
                $version,
                GenerationStage::Match,
                'failed',
                'Ürün önerileri şimdilik hazırlanamadı; tasarımınız hazır.',
                $this->elapsed($started),
            );

            return;
        }

        $this->event(
            $version,
            GenerationStage::Match,
            $matches->isEmpty() ? 'skipped' : 'succeeded',
            $matches->isEmpty()
                ? 'Bu plana uyan ürün bulunamadı.'
                : sprintf('%d ürün önerisi hazır.', $matches->count()),
            $this->elapsed($started),
        );
    }

    // --- internals -----------------------------------------------------------

    /**
     * The constraints, in the shape a prompt can carry.
     *
     * Flattened deliberately: a model reads "south duvarında 1400 mm pencere, kapatılamaz"
     * far better than it reads a nested object, and the placement arithmetic that actually
     * enforces this happens in PHP afterwards regardless.
     *
     * @return array<int, array<string, mixed>>
     */
    private function constraintsFor(Room $room): array
    {
        $room->loadMissing('constraints');

        return $room->constraints->map(static fn ($constraint): array => [
            'type' => $constraint->type->value,
            'wall' => $constraint->wall,
            'width_mm' => $constraint->width_mm,
            'must_stay_visible' => $constraint->must_stay_visible,
            'label' => $constraint->label,
        ])->all();
    }

    private function fail(DesignVersion $version, DesignGenerationFailed $failure): DesignVersion
    {
        $this->event($version, GenerationStage::from($failure->stage), 'failed', $failure->getMessage());

        $failed = $this->tree->markFailed($version->fresh() ?? $version, $failure->getMessage());

        // The credits go back. A render that failed because a provider timed out is our
        // problem, not the customer's, and charging for it is the fastest way to lose them.
        $this->settle($failed, successful: false);

        return $failed;
    }

    /**
     * Consumes or releases the hold taken when the version was created.
     *
     * Reads the reservation from the version rather than taking it as an argument, because
     * the worker that settles is not the request that reserved.
     */
    private function settle(DesignVersion $version, bool $successful): void
    {
        if ($version->credit_reservation_id === null) {
            return;
        }

        $reservation = CreditReservation::query()->find($version->credit_reservation_id);

        if ($reservation === null || ! $reservation->isHeld()) {
            return;
        }

        if ($successful) {
            $this->ledger->consume($reservation, 'Tasarım üretimi');

            return;
        }

        $this->ledger->release($reservation, 'Tasarım üretilemedi');
    }

    private function event(
        DesignVersion $version,
        GenerationStage $stage,
        string $status,
        string $message,
        ?int $durationMs = null,
    ): void {
        DesignVersionEvent::query()->create([
            'design_version_id' => $version->getKey(),
            'stage' => $stage,
            'status' => $status,
            /*
             * Truncated to the column width. An event is a status line somebody reads
             * while they wait, not a log — and a message built from an exception can run
             * to a thousand characters, which would fail the insert and lose the very
             * event that was trying to explain what went wrong.
             */
            'message' => Str::limit($message, 197),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }

    /**
     * A failure kind in words, never the provider's own message.
     *
     * "Rate limit exceeded for org-abc123" tells a customer nothing they can act on and
     * tells a competitor something they would like to know.
     */
    private function reasonFor(AiJob $job): string
    {
        /*
         * Returned exactly as the enum wrote it, and deliberately not lowercased. Turkish
         * has two i letters, and "İstek sınırı" lowercased under Unicode default casing
         * becomes "i̇stek" — an i with a combining dot that renders as a smudge in the
         * middle of a sentence a customer reads. The labels are already written for
         * display; the fix for a capital in mid-sentence is to phrase the sentence around
         * it, not to case-fold Turkish.
         */
        return $job->failure_kind?->label() ?? 'Bilinmeyen hata';
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

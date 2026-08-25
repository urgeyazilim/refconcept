<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\GenerationStage;
use App\Domains\Projects\Enums\RenderQuality;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Jobs\GenerateDesignVersion;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\DesignVersionEvent;
use App\Domains\Projects\Models\RoomAnalysis;
use Illuminate\Support\Facades\DB;

/**
 * Starting a design version: what it costs, who pays, and getting it onto a worker.
 *
 * Pulled out of the controller because both entry points need the identical sequence and
 * neither can be allowed to do three of the four steps. Creating a version without holding
 * credits gives a render away; holding credits without queueing takes payment for nothing.
 *
 * The order is deliberate and worth stating: **quote, then create, then hold, then
 * queue.** Holding before the version exists would leave a reservation with nothing to
 * point at when the branch is refused, and queueing before the hold would race a worker
 * against the customer's own balance.
 */
final class DesignVersionLauncher
{
    public function __construct(
        private readonly DesignVersionTree $tree,
        private readonly DesignGenerationPipeline $pipeline,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Creates a version, pays for it, and hands it to a worker.
     *
     * @throws DesignVersionRefused when the tree will not take it
     * @throws InsufficientCredits when the customer cannot pay for it
     */
    public function launch(
        Design $design,
        ?DesignVersion $parent,
        User $actor,
        RenderQuality $quality = RenderQuality::Draft,
        ?string $userPrompt = null,
        ?string $styleCode = null,
        ?string $stylePrompt = null,
    ): DesignVersion {
        $cost = $this->quote($design, $quality);

        // Branching first, because it is the step that refuses: a room with no
        // photograph, an archived project, a parent that never finished. Taking the
        // customer's credits before finding that out would be the wrong order.
        $version = $this->tree->branch(
            design: $design,
            parent: $parent,
            actor: $actor,
            userPrompt: $userPrompt,
            styleCode: $styleCode,
            stylePrompt: $stylePrompt,
            creditCost: $cost,
        );

        $version->forceFill(['render_quality' => $quality])->save();

        if ($cost > 0) {
            try {
                $reservation = $this->ledger->reserve(
                    user: $actor,
                    credits: $cost,
                    // The version's own id, so a retried request finds its hold rather
                    // than taking a second one.
                    reference: 'design-version:'.$version->getKey(),
                    description: 'Tasarım üretimi',
                    subject: $version,
                    expiresAt: now()->addHours(2),
                );

                $version->forceFill(['credit_reservation_id' => $reservation->getKey()])->save();
            } catch (InsufficientCredits $e) {
                /*
                 * The version is marked failed rather than deleted. A customer who cannot
                 * afford a render should see why on the design they were looking at, not
                 * find that their click did nothing at all — and the tree's numbering
                 * never reuses the number either way.
                 */
                $this->tree->markFailed($version, $e->getMessage());

                throw $e;
            }
        }

        $this->announce($version);

        /*
         * Dispatched after the transaction commits. A worker is a separate process and
         * can pick the job up within milliseconds — before an uncommitted row is visible
         * to it — and the symptom is a version that cannot find itself, intermittently,
         * under load.
         */
        DB::afterCommit(static function () use ($version): void {
            GenerateDesignVersion::dispatch((string) $version->getKey());
        });

        return $version;
    }

    /**
     * What this version will cost, before anything is created.
     *
     * A quote rather than a constant: the price comes from the AI routes, so an operator
     * who moves a task onto a cheaper model reprices renders without a deploy. The
     * analysis step is dropped from the quote when the room has already been read, because
     * charging twice for an answer we are about to reuse is not defensible.
     */
    public function quote(Design $design, RenderQuality $quality): int
    {
        $design->loadMissing('room');

        $roomId = $design->room?->getKey();

        $alreadyAnalysed = $roomId !== null && RoomAnalysis::query()
            ->where('room_id', $roomId)
            ->current()
            ->exists();

        return $this->pipeline->costOf($quality, needsAnalysis: ! $alreadyAnalysed);
    }

    /**
     * The first line of progress, written before a worker exists.
     *
     * Without it a customer polling immediately after pressing the button sees an empty
     * list and no explanation — which reads as nothing having happened rather than as
     * something about to.
     */
    private function announce(DesignVersion $version): void
    {
        DesignVersionEvent::query()->create([
            'design_version_id' => $version->getKey(),
            'stage' => GenerationStage::Queued,
            'status' => 'started',
            'message' => 'İsteğiniz sıraya alındı.',
            'created_at' => now(),
        ]);
    }
}

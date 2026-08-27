<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\DesignStatus;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Exceptions\DesignVersionRefused;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Support\Storage\PrivateLinkSigner;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of design versions.
 *
 * A design is a tree because that is how people actually use one. Somebody generates a
 * living room, likes it, asks for a darker sofa, dislikes the result and wants the
 * first one back. A flat list loses the first one the moment the second exists; a tree
 * keeps every attempt and remembers which produced which.
 *
 * Three invariants live here, and each of them is a bug that happened somewhere else
 * first:
 *
 *  - **Version numbers never repeat, even after a failure.** "v4" has to mean the same
 *    thing to a customer tomorrow as it does today, and reusing the number of a failed
 *    attempt means a support conversation about two different v4s.
 *  - **You may only branch from something that finished.** Refining a failed or
 *    half-generated attempt asks the engine to improve an image nobody has.
 *  - **A finished version never changes.** Re-running produces a sibling. That is what
 *    makes "I preferred the third one" actionable rather than nostalgic.
 */
final class DesignVersionTree
{
    public function __construct(private readonly PrivateLinkSigner $links) {}

    /**
     * Starts a new attempt.
     *
     * `$parent` null means a root: the first attempt, or a deliberate fresh start from
     * the original photograph rather than from a previous render.
     *
     * The version is created `pending` and carries no image yet. Phase 6 moves it to
     * `generating` when a provider picks it up and Phase 8 attaches the render; until
     * then this is the shape those phases fill in, and it is fully testable without
     * either of them.
     *
     * @throws DesignVersionRefused
     */
    public function branch(
        Design $design,
        ?DesignVersion $parent,
        User $actor,
        ?string $userPrompt = null,
        ?string $styleCode = null,
        ?string $stylePrompt = null,
        int $creditCost = 0,
    ): DesignVersion {
        $design->loadMissing('room.project');

        $room = $design->room;

        if ($room === null || ! $room->isReadyForDesign()) {
            throw DesignVersionRefused::roomHasNoPhotograph();
        }

        if ($room->project?->status->isEditable() !== true) {
            throw DesignVersionRefused::projectArchived();
        }

        if ($parent !== null) {
            if ($parent->design_id !== $design->getKey()) {
                // Branching across designs would let one room's tree grow a limb from
                // another room's render, which is not a feature but a mix-up.
                throw DesignVersionRefused::parentBelongsElsewhere();
            }

            if (! $parent->status->canBranch()) {
                throw DesignVersionRefused::parentNotReady();
            }
        }

        return DB::transaction(function () use ($design, $parent, $actor, $userPrompt, $styleCode, $stylePrompt, $creditCost): DesignVersion {
            /*
             * The design row is locked while the number is chosen. Two "generate"
             * clicks arriving together would otherwise both read the same maximum and
             * both write v4 — and the unique index would fail one of them, turning a
             * double click into an error the customer sees.
             */
            $locked = Design::query()->lockForUpdate()->findOrFail($design->getKey());

            $version = DesignVersion::query()->create([
                'design_id' => $locked->getKey(),
                'parent_version_id' => $parent?->getKey(),
                'version_number' => $locked->nextVersionNumber(),
                'style_code' => $styleCode,
                'style_prompt' => $stylePrompt,
                'user_prompt' => $userPrompt,
                'credit_cost' => $creditCost,
                'created_by' => $actor->getKey(),
            ]);

            $locked->forceFill(['status' => DesignStatus::Generating])->save();

            return $version;
        });
    }

    /** A provider has picked the version up. */
    public function markGenerating(DesignVersion $version): DesignVersion
    {
        return $this->transition($version, DesignVersionStatus::Generating);
    }

    /**
     * The attempt finished.
     *
     * Becomes the design's current version, because a customer who just generated
     * something wants to look at it. Choosing a different one afterwards is
     * {@see setCurrent()}.
     */
    public function markReady(DesignVersion $version): DesignVersion
    {
        return DB::transaction(function () use ($version): DesignVersion {
            $fresh = $this->transition($version, DesignVersionStatus::Ready, [
                'completed_at' => now(),
            ]);

            Design::query()->whereKey($fresh->design_id)->update([
                'status' => DesignStatus::Ready->value,
                'current_version_id' => $fresh->getKey(),
            ]);

            return $fresh;
        });
    }

    /**
     * The attempt failed, with a reason the customer can read.
     *
     * The design falls back to `ready` when any earlier version succeeded: one failed
     * refinement should not make a design that already has three good images look
     * broken.
     */
    public function markFailed(DesignVersion $version, string $reason): DesignVersion
    {
        return DB::transaction(function () use ($version, $reason): DesignVersion {
            $fresh = $this->transition($version, DesignVersionStatus::Failed, [
                'failure_reason' => $reason,
            ]);

            $hasReady = DesignVersion::query()
                ->where('design_id', $fresh->design_id)
                ->ready()
                ->exists();

            Design::query()->whereKey($fresh->design_id)->update([
                'status' => ($hasReady ? DesignStatus::Ready : DesignStatus::Failed)->value,
            ]);

            return $fresh;
        });
    }

    /**
     * Points the design at a version the customer chose.
     *
     * Going back to v1 after generating v5 is the normal case, not an undo: it is what
     * the whole tree exists to allow.
     *
     * @throws DesignVersionRefused
     */
    public function setCurrent(Design $design, DesignVersion $version): Design
    {
        if ($version->design_id !== $design->getKey()) {
            throw DesignVersionRefused::parentBelongsElsewhere();
        }

        if (! $version->status->canBranch()) {
            throw DesignVersionRefused::parentNotReady();
        }

        $design->forceFill(['current_version_id' => $version->getKey()])->save();

        return $design;
    }

    /**
     * The whole tree, shaped for rendering.
     *
     * Built from one query rather than by walking parents: a design with twenty
     * versions would otherwise be twenty round trips to draw one screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(Design $design): array
    {
        $versions = $design->versions()->with('assets')->get();

        $childrenByParent = [];

        foreach ($versions as $version) {
            $childrenByParent[$version->parent_version_id ?? 'root'][] = $version;
        }

        $build = function (string $key) use (&$build, &$childrenByParent, $design): array {
            $nodes = [];

            foreach ($childrenByParent[$key] ?? [] as $version) {
                $nodes[] = [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'status' => $version->status->value,
                    'status_label' => $version->status->label(),
                    'user_prompt' => $version->user_prompt,
                    'style_code' => $version->style_code,
                    'render_quality' => $version->render_quality->value,
                    // On the node rather than only on the detail endpoint: a failed
                    // version in the middle of a tree needs to say why where it sits, not
                    // make somebody open it to find out.
                    'failure_reason' => $version->failure_reason,
                    'credit_cost' => $version->credit_cost,
                    'is_current' => $design->current_version_id === $version->id,
                    /*
                     * The picture itself, signed.
                     *
                     * It was missing entirely: the render was produced, stored and never
                     * shown. A customer paid credits, waited a minute and got a shopping
                     * list — the list is what they can act on, but the picture is the whole
                     * reason they uploaded a photograph. Without it there is nothing to
                     * imagine, and the product's promise is exactly that you can see it.
                     */
                    'image_url' => $this->assetUrl($version),
                    'created_at' => $version->created_at?->toIso8601String(),
                    'children' => $build((string) $version->id),
                ];
            }

            return $nodes;
        };

        return $build('root');
    }

    /**
     * A short-lived link to a version's render.
     *
     * Null while a version is still generating, or if it failed — both are ordinary states
     * and the screen says so in its own words rather than showing a broken image.
     */
    private function assetUrl(DesignVersion $version): ?string
    {
        $asset = $version->render();

        if ($asset === null) {
            return null;
        }

        try {
            return $this->links->url($asset->disk, $asset->storage_path, now()->addMinutes(30));
        } catch (RuntimeException) {
            // A disk that cannot sign is a local development setup, not a customer problem.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws DesignVersionRefused
     */
    private function transition(
        DesignVersion $version,
        DesignVersionStatus $target,
        array $attributes = [],
    ): DesignVersion {
        if (! $version->status->canTransitionTo($target)) {
            throw DesignVersionRefused::alreadyFinished();
        }

        $version->forceFill([...$attributes, 'status' => $target])->save();

        return $version;
    }
}

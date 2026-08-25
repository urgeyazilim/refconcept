<?php

declare(strict_types=1);

namespace App\Domains\Matching\Http\Controllers;

use App\Domains\Matching\Enums\FeedbackVerdict;
use App\Domains\Matching\Enums\MatchStatus;
use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Matching\Models\DesignMatchFeedback;
use App\Domains\Matching\Services\ShoppingListBuilder;
use App\Domains\Projects\Models\Design;
use App\Domains\Projects\Models\DesignVersion;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The shopping list beside a design.
 *
 * Nested under the project for the same reason everything else in that subtree is: one
 * authorisation check on the parent covers the whole branch, and there is no match id that
 * opens a stranger's flat.
 *
 * The list is read, rebuilt, chosen from and complained about — four verbs, and the fourth
 * is the one that matters most. Similarity scores are the system marking its own homework;
 * a customer saying "not that one" is the only honest signal about whether any of this
 * works.
 */
final class DesignMatchController
{
    public function __construct(private readonly ShoppingListBuilder $builder) {}

    /**
     * The list, grouped the way the plan reads.
     *
     * By placement rather than as a flat list of products: "for the sofa, these five" is
     * the shape of the answer, and a flat list would leave the customer working out which
     * suggestion was for which piece of furniture.
     */
    public function index(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
    ): JsonResponse {
        $this->authorize($request, $project, $room, $design, $version, 'view');

        $matches = DesignMatch::query()
            ->with(['product.media', 'sku.dimensions', 'sku.seller'])
            ->forVersion((string) $version->getKey())
            ->get();

        return response()->json([
            'data' => [
                'placements' => $this->groupByPlacement($matches, $version),
                'total_minor' => $this->chosenTotal($matches),
                'currency' => $matches->first()->currency ?? 'TRY',
                'verdicts' => FeedbackVerdict::options(),
            ],
        ]);
    }

    /**
     * Builds the list again.
     *
     * For when the plan has products the catalogue did not have last week, or when a
     * customer has rejected enough of the first attempt to want a fresh one. Rebuilding
     * discards the previous suggestions — merging two generations would produce a list
     * whose order nobody can explain.
     */
    public function rebuild(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
    ): JsonResponse {
        $this->authorize($request, $project, $room, $design, $version);

        $matches = $this->builder->build($version);

        return response()->json([
            'message' => $matches->isEmpty()
                ? 'Bu plana uyan ürün bulunamadı.'
                : sprintf('%d ürün önerisi hazırlandı.', $matches->count()),
            'data' => ['count' => $matches->count()],
        ]);
    }

    /**
     * Chooses one suggestion for a placement.
     *
     * The others for that placement become `replaced` rather than staying `suggested`,
     * because a customer who picked the second sofa has said something about the first —
     * and a list where four things are still "suggested" after one was chosen does not
     * reflect what happened.
     */
    public function choose(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
        DesignMatch $match,
    ): JsonResponse {
        $this->authorize($request, $project, $room, $design, $version);
        abort_unless($match->design_version_id === $version->getKey(), 404);

        DesignMatch::query()
            ->where('design_version_id', $version->getKey())
            ->where('placement_index', $match->placement_index)
            ->where('id', '!=', $match->getKey())
            ->where('status', MatchStatus::Accepted->value)
            ->update(['status' => MatchStatus::Replaced->value]);

        $match->forceFill(['status' => MatchStatus::Accepted])->save();

        return response()->json([
            'message' => 'Seçiminiz kaydedildi.',
            'data' => ['id' => $match->id, 'status' => $match->status->value],
        ]);
    }

    /**
     * Records what the customer thought.
     *
     * Every verdict is kept rather than the latest overwriting the last: somebody who says
     * "too expensive" and then "wrong style" has said two things, and only one of them is
     * about the price.
     *
     * A negative verdict also marks the suggestion rejected, so the next rebuild does not
     * propose the same product for the same spot. That is the one place feedback changes
     * behaviour, and it is deliberately narrow — a system that retuned itself on a handful
     * of clicks would be unpredictable in a way nobody could debug.
     */
    public function feedback(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
        DesignMatch $match,
    ): JsonResponse {
        $this->authorize($request, $project, $room, $design, $version);
        abort_unless($match->design_version_id === $version->getKey(), 404);

        $validated = $request->validate([
            'verdict' => ['required', Rule::enum(FeedbackVerdict::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $verdict = FeedbackVerdict::from((string) $validated['verdict']);

        DesignMatchFeedback::query()->create([
            'match_id' => $match->getKey(),
            'user_id' => $request->user()?->getKey(),
            'verdict' => $verdict,
            'reason_code' => $verdict->blames(),
            'note' => $validated['note'] ?? null,
            'created_at' => now(),
        ]);

        if (! $verdict->isPositive() && $match->status === MatchStatus::Suggested) {
            $match->forceFill(['status' => MatchStatus::Rejected])->save();
        }

        return response()->json(['message' => 'Geri bildiriminiz için teşekkürler.']);
    }

    // --- payloads ------------------------------------------------------------

    /**
     * @param  Collection<int, DesignMatch>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function groupByPlacement(Collection $matches, DesignVersion $version): array
    {
        $version->loadMissing('plan');

        $placements = $version->plan->placements ?? [];

        return $matches
            ->groupBy('placement_index')
            ->map(function (Collection $group, int|string $index) use ($placements): array {
                $placement = $placements[(int) $index] ?? [];

                return [
                    'index' => (int) $index,
                    'category' => $group->first()?->placement_category,
                    'wall' => is_array($placement) ? ($placement['wall'] ?? null) : null,
                    'max_width_mm' => is_array($placement) ? ($placement['max_width_mm'] ?? null) : null,
                    'matches' => $group->map(fn (DesignMatch $match): array => $this->payload($match))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DesignMatch $match): array
    {
        $cover = $match->product?->media?->firstWhere('is_cover', true)
            ?? $match->product?->media?->first();

        return [
            'id' => $match->id,
            'rank' => $match->rank,
            'status' => $match->status->value,
            'status_label' => $match->status->label(),

            'product' => [
                'id' => $match->product_id,
                'name' => $match->product?->name,
                'slug' => $match->product?->slug,
                'image_url' => $cover?->url(),
            ],

            'sku' => [
                'id' => $match->sku_id,
                'variant' => $match->sku?->variant_label,
                'seller' => $match->sku?->seller?->display_name,
                'width_mm' => $match->sku?->dimensions?->width_mm,
            ],

            /*
             * The price as it was when the list was built, and today's beside it when the
             * two differ. Hiding a change would be the wrong kind of tidy: the difference
             * is the single most useful thing this row can tell a returning customer.
             */
            'price' => [
                'amount_minor' => $match->price_minor->amountMinor,
                'currency' => $match->currency,
            ],
            'current_price_minor' => $match->sku?->effectivePrice()->amountMinor,
            'price_has_moved' => $match->priceHasMoved(),

            'score_bps' => $match->score_bps,
            'similarity_bps' => $match->similarity_bps,
            'reason' => $match->reason,
        ];
    }

    /**
     * What the chosen items add up to.
     *
     * Only what the customer actually picked. Summing the suggestions would produce a
     * number five times the real one and put it next to the word "toplam".
     *
     * @param  Collection<int, DesignMatch>  $matches
     */
    private function chosenTotal(Collection $matches): int
    {
        return (int) $matches
            ->filter(static fn (DesignMatch $match): bool => $match->status === MatchStatus::Accepted)
            ->sum(static fn (DesignMatch $match): int => $match->price_minor->amountMinor);
    }

    private function authorize(
        Request $request,
        Project $project,
        Room $room,
        Design $design,
        DesignVersion $version,
        string $ability = 'update',
    ): void {
        abort_unless($request->user()?->can($ability, $project) === true, 403);
        abort_unless($room->project_id === $project->getKey(), 404);
        abort_unless($design->room_id === $room->getKey(), 404);
        abort_unless($version->design_id === $design->getKey(), 404);
    }
}

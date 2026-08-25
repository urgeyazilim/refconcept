<?php

declare(strict_types=1);

namespace App\Domains\Matching\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Projects\Models\DesignPlan;
use App\Domains\Projects\Models\DesignVersion;
use App\Support\ValueObjects\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Turns a design plan into things a customer can buy.
 *
 * One placement at a time: "a sofa up to 2200mm against the south wall" becomes a
 * shortlist of sofas that are in stock, fit, cost less than the budget allows, and look
 * like the design. The plan is the input because the plan is the only part of a design
 * that is *written down* — reading the picture back would be guessing at what we already
 * decided.
 *
 * Three stages, and the order is the point:
 *
 *  1. **Filter in SQL.** Category, stock, budget, width. Facts, enforced by the database.
 *  2. **Rank by similarity.** Which of the survivors looks like this design.
 *  3. **Rerank the shortlist with a model**, and only the shortlist. A rerank over four
 *     hundred candidates is a bill; over eight it is a sentence of judgement about things
 *     already known to fit.
 *
 * The rerank is optional in the strongest sense: if it fails, the list built from
 * similarity is returned unchanged. A customer with a slightly worse-ordered shopping
 * list is in a far better position than a customer with no shopping list.
 */
final class ShoppingListBuilder
{
    /** How many suggestions to keep per placement. */
    private const KEEP_PER_PLACEMENT = 5;

    /** How many go to the model for reranking. More is a bill, fewer is not a shortlist. */
    private const RERANK_WINDOW = 10;

    public function __construct(
        private readonly ProductEmbedder $embedder,
        private readonly CandidateQuery $candidates,
        private readonly AiJobDispatcher $dispatcher,
    ) {}

    /**
     * Builds the list for one design version, replacing whatever was there.
     *
     * Replaced rather than added to, because a rebuild happens when something changed —
     * a new plan, a fresh catalogue — and merging two generations of suggestions would
     * produce a list nobody can explain the order of.
     *
     * @return Collection<int, DesignMatch>
     */
    public function build(DesignVersion $version): Collection
    {
        $version->loadMissing(['plan', 'design.room.project']);

        $plan = $version->plan;

        if ($plan === null) {
            return collect();
        }

        $room = $version->design?->room;
        /*
         * The project's budget is a Money value object; everything below works in minor
         * units because that is what a SQL comparison against a price column needs.
         */
        $budget = $version->design?->room?->project?->budget_minor?->amountMinor;

        /*
         * Products already chosen for an earlier placement are excluded from later ones.
         * Two placements that both want "a lamp" should produce two different lamps, and a
         * list with the same product twice reads as a bug whether or not it is one.
         */
        $alreadyUsed = [];
        $built = collect();

        DB::transaction(function () use ($version, $plan, $room, $budget, &$alreadyUsed, &$built): void {
            DesignMatch::query()->where('design_version_id', $version->getKey())->delete();

            foreach ($plan->placements as $index => $placement) {
                if (! is_array($placement)) {
                    continue;
                }

                $matches = $this->forPlacement(
                    version: $version,
                    plan: $plan,
                    placement: $placement,
                    index: (int) $index,
                    roomType: $room?->room_type->value,
                    budgetMinor: $this->perPlacementBudget($budget, count($plan->placements)),
                    exclude: $alreadyUsed,
                );

                foreach ($matches as $match) {
                    $built->push($match);
                }

                $chosen = $matches->first();

                if ($chosen !== null) {
                    $alreadyUsed[] = $chosen->product_id;
                }
            }
        });

        return $built;
    }

    /**
     * @param  array<string, mixed>  $placement
     * @param  array<int, string>  $exclude
     * @return Collection<int, DesignMatch>
     */
    private function forPlacement(
        DesignVersion $version,
        DesignPlan $plan,
        array $placement,
        int $index,
        ?string $roomType,
        ?int $budgetMinor,
        array $exclude,
    ): Collection {
        $category = (string) ($placement['category'] ?? '');

        if ($category === '') {
            return collect();
        }

        $query = $this->queryTextFor($plan, $placement);

        try {
            $vector = $this->embedder->embedQuery($query);
        } catch (Throwable) {
            /*
             * No vector, no shortlist. Returning nothing for this placement is honest: the
             * alternative is falling back to "the cheapest thing in the category", which
             * looks like a recommendation and is not one.
             */
            return collect();
        }

        $rows = $this->candidates->nearest($vector, [
            'category' => $category,
            'room_type' => $roomType,
            'max_price_minor' => $budgetMinor,
            'max_width_mm' => $this->widthOf($placement),
            'exclude_product_ids' => $exclude,
        ], self::RERANK_WINDOW);

        if ($rows->isEmpty()) {
            return collect();
        }

        $scored = $this->rerank($rows, $plan, $placement, $query);

        $matches = collect();

        foreach ($scored->take(self::KEEP_PER_PLACEMENT)->values() as $rank => $row) {
            $matches->push(DesignMatch::query()->create([
                'design_version_id' => $version->getKey(),
                'placement_index' => $index,
                'placement_category' => $category,
                'product_id' => $row->product_id,
                'sku_id' => $row->sku_id,
                'rank' => $rank + 1,
                'score_bps' => $row->score_bps,
                'similarity_bps' => $row->similarity_bps,
                'rerank_bps' => $row->rerank_bps ?? null,
                'reason' => $row->reason,
                'price_minor' => Money::of((int) $row->price_minor, (string) $row->currency),
                'currency' => (string) $row->currency,
            ]));
        }

        return $matches;
    }

    /**
     * Asks a model to reorder the shortlist, and carries on without it if it will not.
     *
     * Everything here already fits, is in stock and is affordable — the model is being
     * asked the one question SQL cannot answer, which is which of them belongs in *this*
     * room. Its opinion is blended with the similarity rather than replacing it, because a
     * model that has decided a beige sofa is "perfect" for every design would otherwise
     * flatten the ranking entirely.
     *
     * @param  Collection<int, stdClass>  $rows
     * @param  array<string, mixed>  $placement
     * @return Collection<int, stdClass>
     */
    private function rerank(Collection $rows, DesignPlan $plan, array $placement, string $query): Collection
    {
        $withSimilarity = $rows->map(function (stdClass $row): stdClass {
            $row->similarity_bps = $this->candidates->similarityBps((float) $row->distance);
            $row->score_bps = $row->similarity_bps;
            $row->rerank_bps = null;
            $row->reason = null;

            return $row;
        });

        try {
            $job = $this->dispatcher->runInline(
                task: AiTask::ProductMatchRerank,
                input: [
                    'plan' => ['style' => $plan->style, 'palette' => $plan->palette],
                    'placement' => $placement,
                    'query' => $query,
                    'candidates' => $withSimilarity->values()->map(static fn (stdClass $row, int $i): array => [
                        'candidate' => $i,
                        'name' => $row->product_name,
                        'category' => $row->category_name,
                        'width_mm' => $row->width_mm,
                        'price_minor' => (int) $row->price_minor,
                    ])->all(),
                ],
                creditCostOverride: 0,
            );
        } catch (Throwable) {
            return $withSimilarity->sortByDesc('score_bps')->values();
        }

        if ($job->status !== AiJobStatus::Succeeded) {
            // A worse-ordered list is a far better outcome than no list.
            return $withSimilarity->sortByDesc('score_bps')->values();
        }

        /** @var array<int, array<string, mixed>> $ranking */
        $ranking = (array) ($job->output['structured']['ranking'] ?? []);

        foreach ($ranking as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $index = $entry['candidate'] ?? null;
            $score = $entry['score'] ?? null;

            if (! is_int($index) || ! is_numeric($score)) {
                continue;
            }

            $row = $withSimilarity->get($index);

            if ($row === null) {
                continue;
            }

            $row->rerank_bps = max(0, min(10_000, (int) round((float) $score * 10_000)));

            /*
             * Sixty–forty in favour of the model, because it is the one that can see the
             * design and the similarity is a proxy for the same question. Not a hundred
             * per cent, so a model with a favourite cannot bury a genuinely closer match.
             */
            $row->score_bps = (int) round(($row->rerank_bps * 0.6) + ($row->similarity_bps * 0.4));

            if (isset($entry['reason']) && is_string($entry['reason'])) {
                $row->reason = mb_substr($entry['reason'], 0, 300);
            }
        }

        return $withSimilarity->sortByDesc('score_bps')->values();
    }

    /**
     * The words the catalogue is searched with.
     *
     * The category and the style rather than the customer's original prompt: the prompt
     * describes a *room* and this is a search for one piece of furniture in it. Including
     * "geniş ve aydınlık salon" would pull every result towards products whose
     * descriptions happen to mention rooms.
     *
     * @param  array<string, mixed>  $placement
     */
    private function queryTextFor(DesignPlan $plan, array $placement): string
    {
        $parts = array_filter([
            (string) ($placement['category'] ?? ''),
            $plan->style,
            is_array($plan->palette) ? implode(' ', array_slice($plan->palette, 0, 3)) : null,
            is_string($placement['notes'] ?? null) ? $placement['notes'] : null,
        ]);

        return trim(implode(' ', $parts));
    }

    /**
     * What one placement may cost.
     *
     * An even share of the project's budget, which is crude and defensible: the sofa will
     * cost more than the lamp, and pretending to know the split without asking the
     * customer would be inventing a preference. Generous by half again, because a hard
     * even split would exclude the one expensive item every room has.
     */
    private function perPlacementBudget(?int $budgetMinor, int $placements): ?int
    {
        if ($budgetMinor === null || $budgetMinor <= 0 || $placements <= 0) {
            return null;
        }

        return (int) round(($budgetMinor / $placements) * 1.5);
    }

    /**
     * @param  array<string, mixed>  $placement
     */
    private function widthOf(array $placement): ?int
    {
        $width = $placement['max_width_mm'] ?? null;

        return is_int($width) && $width > 0 ? $width : null;
    }
}

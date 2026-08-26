<?php

declare(strict_types=1);

namespace App\Domains\Finance\Services;

use App\Domains\Finance\Models\CommissionRule;
use App\Domains\Sellers\Models\Seller;

/**
 * What the platform keeps on one line, and why.
 *
 * The hierarchy is the one in 06_SECURITY_PAYMENT_FINANCE_RULES.md, most specific first:
 *
 *   1. the order item's own snapshot      — already decided, never re-derived
 *   2. a campaign override                — a deliberate, dated exception
 *   3. seller + category                  — a rate negotiated for one kind of goods
 *   4. seller                             — a rate negotiated with one shop
 *   5. category                           — what this kind of goods costs anybody
 *   6. the platform default               — the fallback, of which there is exactly one
 *
 * Rung 1 is why this class is never asked about an existing order: the snapshot *is* the
 * answer, and re-resolving it would let a rate change rewrite what a seller earned last
 * quarter. The resolver runs once, at order time, and its answer is copied onto the line.
 *
 * The result carries the rule that decided it, not just a number. "Why is my commission
 * 14%" is the single most common question a seller asks, and an answer of "because of the
 * September campaign" is worth the extra field.
 */
final class CommissionResolver
{
    /** Used when nothing at all is configured, so a sale can never post an unpriced line. */
    private const LAST_RESORT_BPS = 1_200;

    public function resolve(?string $sellerId, ?string $categoryId): CommissionDecision
    {
        $candidates = CommissionRule::query()
            ->live()
            ->where(function ($query) use ($sellerId, $categoryId): void {
                $query->where('scope', 'platform')
                    ->orWhere('scope', 'campaign')
                    ->orWhere(fn ($q) => $q->where('scope', 'category')->where('category_id', $categoryId))
                    ->orWhere(fn ($q) => $q->where('scope', 'seller')->where('seller_id', $sellerId))
                    ->orWhere(fn ($q) => $q->where('scope', 'seller_category')
                        ->where('seller_id', $sellerId)
                        ->where('category_id', $categoryId));
            })
            ->get()
            ->filter(fn (CommissionRule $rule): bool => $this->applies($rule, $sellerId, $categoryId));

        /*
         * A seller's own negotiated rate, when nobody has written a rule row for it.
         *
         * `sellers.default_commission_bps` predates this table and is what the seller
         * administration screen still writes. Treating it as the `seller` rung keeps that
         * feature working instead of leaving it set and silently ignored — which is the
         * worse of the two failures, because the screen would keep saying it had taken.
         */
        $sellerColumn = $sellerId === null
            ? null
            : Seller::query()->whereKey($sellerId)->value('default_commission_bps');

        if ($sellerColumn !== null && ! $candidates->contains(fn (CommissionRule $rule): bool => $rule->scope === 'seller')) {
            $candidates->push(new CommissionRule([
                'scope' => 'seller',
                'seller_id' => $sellerId,
                'rate_bps' => (int) $sellerColumn,
                'priority' => 100,
                'label' => 'Satıcı sözleşme oranı',
            ]));
        }

        if ($candidates->isEmpty()) {
            return new CommissionDecision(self::LAST_RESORT_BPS, 'fallback', null);
        }

        /*
         * Sorted by specificity, then by the operator's own priority, then by the newest.
         *
         * `priority` breaks ties within a rung rather than replacing the rung order — an
         * operator who wants a campaign to beat a negotiated seller rate says so by
         * choosing the campaign scope, not by inventing a number.
         */
        $winner = $candidates
            ->sortBy(fn (CommissionRule $rule): string => sprintf(
                '%d-%03d-%011d',
                $this->specificity($rule),
                $rule->priority,
                // Newest first inside a tie, expressed as a descending number so the whole
                // ordering stays one ascending string sort — several sort keys passed as
                // closures are not the same thing to Collection::sortBy(), and getting
                // that wrong picks a plausible rule rather than the right one.
                99_999_999_999 - ($rule->created_at?->getTimestamp() ?? 0),
            ))
            ->first();

        return new CommissionDecision(
            $winner->rate_bps,
            $winner->scope,
            $winner->getKey(),
            $winner->label,
        );
    }

    /** What the platform keeps on an amount, rounded to the kuruş. */
    public function amountFor(int $lineTotalMinor, int $rateBps): int
    {
        return (int) round($lineTotalMinor * $rateBps / 10_000);
    }

    /**
     * A campaign rule with a seller or a category set applies only to those.
     *
     * The schema deliberately leaves campaign rows unconstrained — a campaign can be
     * platform-wide, one seller's, or one category's — so the narrowing happens here.
     */
    private function applies(CommissionRule $rule, ?string $sellerId, ?string $categoryId): bool
    {
        if ($rule->scope !== 'campaign') {
            return true;
        }

        if ($rule->seller_id !== null && $rule->seller_id !== $sellerId) {
            return false;
        }

        return ! ($rule->category_id !== null && $rule->category_id !== $categoryId);
    }

    /** Lower is more specific, so an ordinary ascending sort puts the winner first. */
    private function specificity(CommissionRule $rule): int
    {
        return match ($rule->scope) {
            'campaign' => 1,
            'seller_category' => 2,
            'seller' => 3,
            'category' => 4,
            default => 5,
        };
    }
}

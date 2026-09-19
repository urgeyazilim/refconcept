<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Matching\Enums\MatchStatus;
use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Projects\Models\DesignVersion;
use Illuminate\Support\Str;

/**
 * The products a design actually settled on, with the sizes needed to place them.
 *
 * Between the shopping list and the floor plan. The matches know which product was chosen
 * for which placement; the plan knows which wall that placement was meant for; the catalogue
 * knows how big the variant is. None of them knows all three, and the composer needs all
 * three at once.
 *
 * A variant with no measurements is left out rather than guessed at. The whole point of the
 * plan is that it is measured — a box at an invented size is a promise that it fits, made on
 * no evidence, to somebody who is about to pay for a delivery.
 */
final class ComposableProducts
{
    /**
     * @return array{pieces: list<array<string, mixed>>, unmeasured: list<array<string, mixed>>}
     */
    public function forVersion(DesignVersion $version): array
    {
        $version->loadMissing('plan');

        // The plan may be missing entirely — a version that failed before it wrote one — and
        // that is a design with no wall hints rather than an error: the composer falls back to
        // the emptiest wall for every piece.
        $placements = $version->plan === null ? [] : $version->plan->placements;

        $matches = DesignMatch::query()
            ->where('design_version_id', $version->getKey())
            ->whereIn('status', [MatchStatus::Accepted, MatchStatus::Suggested])
            ->with(['sku.dimensions', 'product.primaryCategory'])
            ->orderBy('placement_index')
            ->orderBy('rank')
            ->get();

        $pieces = [];
        $unmeasured = [];

        /** @var array<int, bool> $taken */
        $taken = [];

        foreach ($matches as $match) {
            /*
             * One product per placement.
             *
             * The matcher offers several for each, ranked, and an accepted one wins outright.
             * Placing all of them would furnish the room with every sofa the customer was
             * shown — which is a real way to end up with three sofas in a rendering.
             */
            if (($taken[$match->placement_index] ?? false) === true) {
                continue;
            }

            $dimensions = $match->sku?->dimensions;

            $width = (int) ($dimensions->width_mm ?? 0);
            $depth = (int) ($dimensions->depth_mm ?? 0);

            if ($width <= 0 || $depth <= 0) {
                $unmeasured[] = [
                    'product_id' => $match->product_id,
                    'category' => $match->placement_category,
                ];

                continue;
            }

            $taken[$match->placement_index] = true;

            $placement = $placements[$match->placement_index] ?? null;

            /*
             * As many as the plan asked for, of the one product chosen for the placement.
             *
             * "İki berjer" is one placement with a quantity of two, and it arrived here as one
             * armchair: the number was read when the placement was written and never again.
             * The design the product owner was looking at had two chairs either side of the
             * coffee table and the room they were given had one, against a wall.
             *
             * One product rather than two different ones, because that is what a pair is —
             * and the basket already turns two rows of the same variant into a quantity of
             * two rather than two lines.
             */
            $wanted = $this->quantity($placement);

            for ($copy = 0; $copy < $wanted; $copy++) {
                $pieces[] = [
                    'product_id' => (string) $match->product_id,
                    'sku_id' => (string) $match->sku_id,
                    // The placement's category rather than the product's, because the placement
                    // is what the room was planned around: a bench bought as seating is seating.
                    'category' => $match->placement_category,
                    'width_mm' => $width,
                    'depth_mm' => $depth,
                    'height_mm' => (int) ($dimensions->height_mm ?? 0) ?: null,
                    'wall' => $this->wall(is_array($placement) ? ($placement['wall'] ?? null) : null),
                ];
            }
        }

        return ['pieces' => $pieces, 'unmeasured' => $unmeasured];
    }

    /**
     * How many of a placement the plan asked for.
     *
     * Capped, because this number comes out of a language model and the composer turns each
     * one into a piece of furniture standing in somebody's room. Four is more chairs than any
     * plan has ever asked for and fewer than a runaway number would put on the floor; the
     * composer's own floor-share rule stops the rest.
     */
    private function quantity(mixed $placement): int
    {
        if (! is_array($placement)) {
            return 1;
        }

        $quantity = $placement['quantity'] ?? 1;

        if (is_string($quantity) && ctype_digit($quantity)) {
            $quantity = (int) $quantity;
        }

        if (! is_int($quantity)) {
            return 1;
        }

        return max(1, min(4, $quantity));
    }

    /**
     * The wall a placement asked for, in the four words the geometry uses.
     *
     * The plan is written in Turkish because the model is asked in Turkish, so "kuzey duvarı"
     * and "Kuzey" both arrive and both mean north. Anything else — "pencere tarafı", "sol" —
     * becomes null, and the composer picks the emptiest wall instead of inventing a meaning
     * for a direction it cannot resolve.
     */
    private function wall(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $text = Str::lower(Str::ascii($value));

        return match (true) {
            str_contains($text, 'kuzey'), str_contains($text, 'north') => 'north',
            str_contains($text, 'guney'), str_contains($text, 'south') => 'south',
            str_contains($text, 'dogu'), str_contains($text, 'east') => 'east',
            str_contains($text, 'bati'), str_contains($text, 'west') => 'west',
            default => null,
        };
    }
}

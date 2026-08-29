<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How close one style sits to another.
 *
 * Matching needs this because filtering hard on the chosen style empties the room. With
 * twelve products in the catalogue, a customer choosing "Lüks" and getting a strict
 * `WHERE style = luxury` sees nothing — not because the shop has nothing for them but
 * because it has nothing tagged that exact word. A customer reads that as a broken page.
 *
 * So style ranks rather than filters. A luxury request puts luxury pieces first, classic
 * ones just behind them, and industrial ones last — everything is reachable and the order
 * carries the preference. As the catalogue fills the strict matches crowd out the near
 * ones on their own, without a rule changing.
 *
 * The numbers are judgement rather than measurement, which is exactly why they live in a
 * table: tuning them from what customers actually accept should be an UPDATE, not a deploy.
 */
final class StyleAffinity
{
    /** A style is a perfect match for itself; nothing else is. */
    private const SELF_AFFINITY = 10_000;

    /**
     * How much of the score style is allowed to move.
     *
     * A quarter. Style is a preference, not a specification: somebody who asked for modern
     * and is shown a scandinavian sofa that fits their room, their budget and their wall has
     * been served well, and a weighting that let style overrule the measurements would put
     * the right-looking sofa in a room it does not fit.
     */
    public const WEIGHT_BPS = 2_500;

    /** Cached for an hour: read on every placement of every design, written by hand. */
    private const CACHE_TTL_SECONDS = 3_600;

    /**
     * Affinity between two styles, in basis points.
     *
     * 10000 for the same style, 5000-8000 for a neighbour, 0 for anything unrelated. A
     * missing style on either side is 0 rather than an error — an untagged product should
     * rank last, not break the search.
     */
    public function between(?string $wanted, ?string $found): int
    {
        if ($wanted === null || $found === null) {
            return 0;
        }

        if ($wanted === $found) {
            return self::SELF_AFFINITY;
        }

        return $this->map()[$wanted][$found] ?? 0;
    }

    /**
     * The whole map, keyed by style code both ways round.
     *
     * One query and one cache entry rather than a lookup per placement: a design with
     * fourteen placements against a shortlist of twenty each would otherwise ask the same
     * small question two hundred and eighty times.
     *
     * @return array<string, array<string, int>>
     */
    public function map(): array
    {
        /** @var array<string, array<string, int>> $map */
        $map = Cache::remember('catalog:style-affinity', self::CACHE_TTL_SECONDS, function (): array {
            $rows = DB::table('style_adjacency as a')
                ->join('styles as s', 's.id', '=', 'a.style_id')
                ->join('styles as n', 'n.id', '=', 'a.neighbour_style_id')
                ->get(['s.code as style', 'n.code as neighbour', 'a.affinity_bps']);

            $built = [];

            foreach ($rows as $row) {
                $built[$row->style][$row->neighbour] = (int) $row->affinity_bps;
            }

            return $built;
        });

        return $map;
    }

    /** Called when the adjacency table changes, which is rarely and by hand. */
    public function forget(): void
    {
        Cache::forget('catalog:style-affinity');
    }
}

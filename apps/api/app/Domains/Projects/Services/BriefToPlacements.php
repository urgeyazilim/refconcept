<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\DesignBrief;
use App\Domains\Projects\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * Turns what the customer chose into the list of things to put in the room.
 *
 * This is the inversion the whole guided design rests on. The model used to decide what
 * furniture a room needed and the search then hunted for it — which is backwards twice
 * over: the customer knows whether they want a corner sofa better than any model does, and
 * the catalogue knows what it stocks better than either. So the answers become the
 * programme, and the model keeps the job it is actually good at, which is deciding where
 * things go and drawing them.
 *
 * The output is deliberately the same shape the model used to produce — `{category, wall,
 * max_width_mm}` — so the plan stage, the placement validator and the shopping list all
 * carry on unchanged. What has changed is who decided.
 *
 * Widths are computed rather than asked for. Nobody knows how wide a sofa should be in
 * their own living room, and asking would be the textarea again in a different costume; the
 * room's own measurements and a share-of-wall rule per category answer it better than a
 * person could.
 */
final class BriefToPlacements
{
    /**
     * How much of the longest wall each category may claim, in basis points.
     *
     * Judgement, in one table, rather than scattered through the code. A sofa may take
     * three-fifths of a wall and still leave a room walkable; a console at the same share
     * would look like a barricade. Anything unlisted falls back to a third, which is
     * conservative on purpose — a piece too small for its spot is a disappointment, a piece
     * too large is a return.
     */
    private const WALL_SHARE_BPS = [
        'oturma-grubu' => 7_000,
        'kanepe' => 6_000,
        'yatak' => 5_500,
        'gardirop' => 5_500,
        'yemek-masasi' => 5_000,
        'tv-unitesi' => 5_000,
        'mutfak-dolabi' => 6_000,
        'kitaplik' => 4_000,
        'konsol' => 4_000,
        'hali' => 8_000,
        'perde' => 9_500,
        'koltuk' => 2_500,
        'sehpa' => 2_500,
        'komodin' => 1_500,
        'sandalye' => 1_500,
        'bar-taburesi' => 1_200,
        'puf' => 1_200,
        'tablo' => 3_500,
        'ayna' => 2_500,
        'bitki' => 1_200,
        'vazo' => 800,
        'kirlent' => 600,
        'lambader' => 1_200,
        'masa-lambasi' => 800,
        'tavan-aydinlatma' => 2_500,
        'duvar-aydinlatma' => 1_500,
    ];

    private const DEFAULT_SHARE_BPS = 3_300;

    /**
     * How much of the budget each kind of thing is worth, relative to the others.
     *
     * Weights rather than percentages, normalised across whatever the customer actually
     * chose — so a brief with four items and a brief with fourteen both spend the whole
     * budget, and dropping the rug gives the sofa more room rather than leaving money
     * unspent.
     *
     * The equal split this replaces was quietly indefensible: a hundred and fifty thousand
     * lira across ten placements gave the sofa the same allowance as a cushion, and the
     * only sofa in the catalogue was excluded from its own living room for costing more
     * than a fifteen-thousand-lira ceiling nobody chose. The customer saw a shopping list
     * with everything on it except the thing the room is built around.
     */
    private const BUDGET_WEIGHT = [
        'oturma-grubu' => 34,
        'kanepe' => 28,
        'yatak' => 24,
        'gardirop' => 22,
        'mutfak-dolabi' => 22,
        'yemek-masasi' => 16,
        'tezgah' => 14,
        'tv-unitesi' => 12,
        'hali' => 12,
        'koltuk' => 12,
        'kitaplik' => 10,
        'konsol' => 9,
        'banyo-dolabi' => 9,
        'sehpa' => 7,
        'lavabo' => 6,
        'perde' => 6,
        'komodin' => 5,
        'nevresim' => 5,
        'sandalye' => 5,
        'tavan-aydinlatma' => 5,
        'lambader' => 4,
        'bar-taburesi' => 4,
        'tablo' => 4,
        'ayna' => 4,
        'puf' => 3,
        'masa-lambasi' => 3,
        'duvar-aydinlatma' => 3,
        'banyo-aksesuar' => 2,
        'bitki' => 2,
        'vazo' => 2,
        'kirlent' => 1,
    ];

    /** Anything unlisted sits with the small decorative things rather than the furniture. */
    private const DEFAULT_WEIGHT = 4;

    /**
     * How far over its share a suggestion may go.
     *
     * A ceiling exactly at the share would mean a sofa priced a hundred lira over its slice
     * is invisible, which is not how anybody shops. Half again is loose enough to be useful
     * and tight enough that a total still lands near the budget, because most placements
     * come in under their share.
     */
    private const BUDGET_TOLERANCE_BPS = 15_000;

    /**
     * The smallest ceiling any one placement gets, as a share of the whole budget.
     *
     * Weights describe how a budget *should* be divided; they do not describe how the
     * catalogue is priced. A framed canvas is worth two per cent of a room by any sensible
     * reckoning and costs eight, because a picture costs what a picture costs however large
     * the room budget is — so a strict share priced the artwork and the plant out of a
     * hundred-and-fifty-thousand-lira living room while the sofa had money to spare.
     *
     * The ceiling exists to stop something absurd being suggested, not to allocate to the
     * lira. A floor keeps it doing the first job without pretending to do the second.
     */
    private const BUDGET_FLOOR_BPS = 800;

    /**
     * The placements a brief asks for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(DesignBrief $brief, Room $room): array
    {
        if ($brief->programme_id === null) {
            return [];
        }

        $longestWall = $this->longestWall($room);

        $placements = [];

        foreach ($this->chosenOptions($brief) as $option) {
            foreach ($this->categoriesFor((string) $option->id) as $category) {
                $quantity = max(1, (int) $category->quantity);

                /*
                 * A set is one placement bought several times; a mixture is several
                 * placements.
                 *
                 * Everything used to be repeated. Six dining chairs became six placements,
                 * and the shopping list refuses to suggest the same product twice — rightly,
                 * because two placements that both want "a lamp" should produce two
                 * different lamps. So a six-person table came back with one chair and five
                 * groups saying nobody sells them, a pair of nightstands came back as one
                 * nightstand, and two armchairs either side of a window came back mismatched.
                 *
                 * Six matching chairs is one decision and one product, and saying so is also
                 * how the shopping list gets to read "×6" instead of listing a chair six
                 * times. "Orta ve yan sehpa" is the other kind: two tables that should not
                 * match, and those stay separate.
                 */
                $entries = ((bool) $category->identical) ? 1 : $quantity;

                for ($index = 0; $index < $entries; $index++) {
                    $placements[] = [
                        'category' => $category->category_slug,
                        'name' => $option->label,
                        'quantity' => $entries === 1 ? $quantity : 1,
                        'max_width_mm' => $this->widthFor((string) $category->category_slug, $longestWall),
                        // The wall is the model's decision. It has the photograph and can
                        // see where the window is; this has a row in a database.
                        'wall' => null,
                        'is_required' => (bool) $category->is_required,
                    ];
                }
            }
        }

        return $this->withBudgetShares($placements, $brief->budget_minor);
    }

    /**
     * Gives each placement its slice of the budget.
     *
     * Done here rather than in the shopping list because this is where the whole plan is
     * visible at once — a share is only meaningful against what else was asked for, and a
     * builder handed one placement at a time can do no better than divide by the count.
     *
     * @param  array<int, array<string, mixed>>  $placements
     * @return list<array<string, mixed>>
     */
    private function withBudgetShares(array $placements, ?int $budgetMinor): array
    {
        if ($budgetMinor === null || $budgetMinor <= 0 || $placements === []) {
            return array_values($placements);
        }

        /*
         * Weighted by how many, because six chairs cost six times one chair.
         *
         * The total counts each placement's weight times its quantity, and the ceiling that
         * comes back out is per unit — the matcher compares it against one product's price.
         * Without the multiplication, six dining chairs would claim one chair's share of the
         * budget and a plan that respected every ceiling would still overspend by five
         * chairs.
         */
        $total = array_sum(array_map(
            fn (array $placement): int => (self::BUDGET_WEIGHT[(string) $placement['category']] ?? self::DEFAULT_WEIGHT)
                * max(1, (int) ($placement['quantity'] ?? 1)),
            $placements,
        ));

        return array_values(array_map(function (array $placement) use ($budgetMinor, $total): array {
            $weight = self::BUDGET_WEIGHT[(string) $placement['category']] ?? self::DEFAULT_WEIGHT;

            $placement['max_price_minor'] = (int) max(
                round($budgetMinor * $weight / max(1, $total) * self::BUDGET_TOLERANCE_BPS / 10_000),
                round($budgetMinor * self::BUDGET_FLOOR_BPS / 10_000),
            );

            return $placement;
        }, $placements));
    }

    /**
     * The options the customer actually picked, in the order they were asked.
     *
     * @return array<int, object>
     */
    private function chosenOptions(DesignBrief $brief): array
    {
        $questions = DB::table('programme_questions')
            ->where('programme_id', $brief->programme_id)
            ->orderBy('position')
            ->get(['id', 'code']);

        $chosen = [];

        foreach ($questions as $question) {
            $codes = $brief->chosen((string) $question->code);

            if ($codes === []) {
                continue;
            }

            $options = DB::table('programme_options')
                ->where('question_id', $question->id)
                ->whereIn('code', $codes)
                // A none-option asks for nothing, and asking the catalogue for nothing is
                // different from not asking. It is skipped here rather than filtered later,
                // where a stray row would put furniture in a room somebody declined.
                ->where('is_none', false)
                ->orderBy('position')
                ->get(['id', 'label']);

            foreach ($options as $option) {
                $chosen[] = $option;
            }
        }

        return $chosen;
    }

    /**
     * @return array<int, object>
     */
    private function categoriesFor(string $optionId): array
    {
        return DB::table('programme_option_categories')
            ->where('option_id', $optionId)
            ->orderBy('position')
            ->get(['category_slug', 'quantity', 'is_required', 'identical'])
            ->all();
    }

    /**
     * How wide a piece of this kind may be in this room.
     *
     * Null when the room was never measured — an unmeasured room gets no ceiling rather
     * than a guessed one, because a made-up limit would quietly exclude products that fit
     * perfectly well and nobody would ever know why.
     */
    private function widthFor(string $categorySlug, ?int $longestWall): ?int
    {
        if ($longestWall === null) {
            return null;
        }

        $share = self::WALL_SHARE_BPS[$categorySlug] ?? self::DEFAULT_SHARE_BPS;

        // Rounded down to the nearest centimetre. Furniture is sold in round numbers and a
        // ceiling of 2187mm reads as a machine talking.
        return (int) (floor($longestWall * $share / 10_000 / 10) * 10);
    }

    private function longestWall(Room $room): ?int
    {
        $sides = array_filter([$room->width_mm, $room->length_mm]);

        return $sides === [] ? null : (int) max($sides);
    }
}

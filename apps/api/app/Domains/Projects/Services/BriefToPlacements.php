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
                /*
                 * Repeated rather than carrying a count.
                 *
                 * "Two nightstands" is two placements, because they end up either side of
                 * the bed and the shopping list needs a line for each. A quantity field
                 * would push the same loop into every consumer, and the first one to forget
                 * it would silently order one nightstand.
                 */
                for ($index = 0; $index < max(1, (int) $category->quantity); $index++) {
                    $placements[] = [
                        'category' => $category->category_slug,
                        'name' => $option->label,
                        'max_width_mm' => $this->widthFor((string) $category->category_slug, $longestWall),
                        // The wall is the model's decision. It has the photograph and can
                        // see where the window is; this has a row in a database.
                        'wall' => null,
                        'is_required' => (bool) $category->is_required,
                    ];
                }
            }
        }

        return $placements;
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
            ->get(['category_slug', 'quantity', 'is_required'])
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

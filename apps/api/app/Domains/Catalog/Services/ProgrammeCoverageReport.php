<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use Illuminate\Support\Facades\DB;

/**
 * Which questions the shop can answer, room by room.
 *
 * The wizard is honest about a thin catalogue — an option nobody stocks is shown disabled
 * with the reason — but honesty on the customer's screen is only half of it. Somebody has
 * to know *which* sellers to go and find, and "the living room feels empty" is not that
 * knowledge. "Salon programındaki 8 sorudan 3'ü karşılanamıyor: tv-unitesi, perde, tablo"
 * is.
 *
 * Every empty row here is a page telling a customer the shop does not sell something, and
 * therefore a reason to sign a seller. That is the number worth putting in front of whoever
 * is doing the signing.
 *
 * Read against the same rules a shopper's search uses — active, approved, priced — because
 * a product a customer cannot buy is a product this must not count.
 */
final class ProgrammeCoverageReport
{
    public function __construct(private readonly CatalogCoverage $coverage) {}

    /**
     * Coverage for every published room programme.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $styleCode = null): array
    {
        $programmes = DB::table('room_programmes')
            ->where('status', 'published')
            ->orderBy('room_type')
            ->get();

        return $programmes
            ->map(fn (object $programme): array => $this->forProgramme($programme, $styleCode))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function forProgramme(object $programme, ?string $styleCode): array
    {
        $questions = DB::table('programme_questions')
            ->where('programme_id', $programme->id)
            ->orderBy('position')
            ->get(['id', 'code', 'prompt']);

        $answerable = 0;
        $gaps = [];

        foreach ($questions as $question) {
            $categories = DB::table('programme_options as o')
                ->join('programme_option_categories as oc', 'oc.option_id', '=', 'o.id')
                ->where('o.question_id', $question->id)
                // A none-option asks for nothing, so it is always answerable and says
                // nothing about the catalogue. Counting it would make every question look
                // half-served whatever the shop stocks.
                ->where('o.is_none', false)
                ->distinct()
                ->pluck('oc.category_slug');

            $stocked = $categories->filter(
                fn (string $slug): bool => $this->coverage->inCategory($slug) > 0,
            );

            /*
             * A question counts as answerable when *any* of its options can be supplied.
             * "Sehpa ister misiniz?" with two of three options in stock is a question a
             * customer can answer well; only a question with nothing behind any of it is
             * a hole.
             */
            if ($stocked->isNotEmpty()) {
                $answerable++;
            }

            foreach ($categories->diff($stocked) as $slug) {
                $gaps[$slug] = ($gaps[$slug] ?? 0) + 1;
            }
        }

        arsort($gaps);

        return [
            'room_type' => $programme->room_type,
            'name' => $programme->name,
            'questions' => $questions->count(),
            'answerable' => $answerable,
            // The categories to go and find sellers for, most-wanted first.
            'missing_categories' => array_keys($gaps),
            'style_code' => $styleCode,
        ];
    }
}

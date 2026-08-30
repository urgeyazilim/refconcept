<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Catalog\Services\CatalogCoverage;
use App\Domains\Projects\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * The questions to ask about one room, and which answers the shop can honour.
 *
 * Every option is checked against the catalogue before it reaches the screen. That check is
 * the point of the whole exercise: a customer offered a television unit by a shop that has
 * never sold one is a customer who will choose it, wait for a render, and be handed a
 * shopping list without it. Which is exactly what used to happen, except the model invented
 * the television unit rather than the customer choosing it.
 *
 * Three verdicts rather than a boolean, because they need three different sentences:
 *
 *   available            offered normally
 *   stocked, wrong style offered, with a note — "modern seçtiniz, bunlar klasik"
 *   nothing at all       shown disabled, or hidden entirely
 *
 * Disabled rather than hidden is the default. A question whose options quietly disappear
 * looks like a shorter question; one that shows what it cannot offer tells the customer
 * something true about the shop, and tells us which sellers to go and find.
 */
final class RoomProgrammeReader
{
    public function __construct(private readonly CatalogCoverage $coverage) {}

    /**
     * The published programme for a room, resolved against the catalogue and the room.
     *
     * @return array<string, mixed>|null null when no programme is published for the type
     */
    public function forRoom(Room $room, ?string $styleCode = null): ?array
    {
        $programme = DB::table('room_programmes')
            ->where('room_type', $room->room_type->value)
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();

        if ($programme === null) {
            return null;
        }

        return [
            'id' => $programme->id,
            'room_type' => $programme->room_type,
            'version' => $programme->version,
            'name' => $programme->name,
            'questions' => $this->questions((string) $programme->id, $room, $styleCode),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function questions(string $programmeId, Room $room, ?string $styleCode): array
    {
        $questions = DB::table('programme_questions')
            ->where('programme_id', $programmeId)
            ->orderBy('position')
            ->get();

        /*
         * Grouped into plain arrays rather than kept as collections.
         *
         * `groupBy` returns a collection keyed by the group value, and reading an element
         * back out of it loses the element type — every downstream `map` then becomes
         * unresolvable to static analysis. Plain arrays say what they hold.
         *
         * @var array<string, array<int, object>> $optionsByQuestion
         */
        $optionsByQuestion = [];

        foreach (DB::table('programme_options')
            ->whereIn('question_id', $questions->pluck('id'))
            ->orderBy('position')
            ->get() as $option) {
            $optionsByQuestion[(string) $option->question_id][] = $option;
        }

        /** @var array<string, array<int, object>> $categoriesByOption */
        $categoriesByOption = [];

        foreach (DB::table('programme_option_categories')
            ->orderBy('position')
            ->get() as $category) {
            $categoriesByOption[(string) $category->option_id][] = $category;
        }

        $longestWall = $this->longestWall($room);

        return $questions->map(function (object $question) use (
            $optionsByQuestion,
            $categoriesByOption,
            $longestWall,
            $styleCode,
        ): array {
            $options = array_map(
                fn (object $option): array => $this->option(
                    $option,
                    $categoriesByOption[(string) $option->id] ?? [],
                    $longestWall,
                    $styleCode,
                ),
                $optionsByQuestion[(string) $question->id] ?? [],
            );

            $answerable = array_filter($options, static fn (array $option): bool => $option['available']);

            return [
                'code' => $question->code,
                'prompt' => $question->prompt,
                'help' => $question->help,
                'kind' => $question->kind,
                /*
                 * A question the shop cannot serve at all stops being required.
                 *
                 * "Yatak ölçüsü" is genuinely required — a bedroom needs a bed, so the
                 * programme marks it so and offers no way past it. Which is correct right
                 * up until nobody is selling beds: then every option is disabled, there is
                 * nothing to tap, and the customer is stranded on step two of seven with no
                 * way forward and no explanation that makes sense to them.
                 *
                 * A new marketplace has an empty catalogue, so this is the normal early
                 * state rather than an edge case. When nothing can be supplied the question
                 * becomes informational: it still says what the room wants and why we
                 * cannot help yet, and it no longer blocks the design.
                 */
                'is_required' => (bool) $question->is_required && $answerable !== [],
                'options' => $options,
            ];
        })->all();
    }

    /**
     * @param  array<int, object>  $categories
     * @return array<string, mixed>
     */
    private function option(object $option, array $categories, ?int $longestWall, ?string $styleCode): array
    {
        $required = array_values(array_filter(
            $categories,
            static fn (object $category): bool => (bool) $category->is_required,
        ));

        /*
         * An option whose parts are all optional is still gated on all of them.
         *
         * `is_required` describes what the *renderer* may drop quietly — "üçlü kanepe +
         * opsiyonel berjer" should not fail because the armchairs sold out. It was never
         * meant to describe what the wizard may offer, and reading it that way made the
         * decor question offer a picture, a plant, a vase and cushions against a catalogue
         * holding none of them: no required categories, so nothing to check, so everything
         * available. Exactly the promise this whole exercise exists to stop making.
         */
        if ($required === []) {
            $required = $categories;
        }

        /*
         * An option is offered when everything it needs can be supplied. Its optional
         * extras are allowed to be missing, as long as something is not.
         */
        $missing = array_values(array_map(
            static fn (object $category): string => (string) $category->category_slug,
            array_filter(
                $required,
                fn (object $category): bool => $this->coverage->inCategory((string) $category->category_slug) === 0,
            ),
        ));

        $exact = array_reduce(
            $required,
            fn (bool $carry, object $category): bool => $carry && $this->coverage
                ->verdict((string) $category->category_slug, $styleCode)['exact'],
            true,
        );

        // A corner sofa in a room whose longest wall is 2200mm is not a suggestion, it is
        // a disappointment with a delivery date. Said before it is chosen rather than
        // dropped by the placement validator afterwards.
        $tooBig = $option->min_wall_mm !== null
            && $longestWall !== null
            && $longestWall < (int) $option->min_wall_mm;

        return [
            'code' => $option->code,
            'label' => $option->label,
            'help' => $option->help,
            // The icon is the whole premise: people choose furniture by looking at it.
            'icon' => $option->icon,
            'is_default' => (bool) $option->is_default,
            'is_none' => (bool) $option->is_none,
            'available' => $missing === [] && ! $tooBig,
            // True when everything it needs exists in the chosen style. False means it is
            // still offered, with a note, rather than withheld.
            'exact_style' => $exact,
            'unavailable_reason' => $this->reason($missing, $tooBig, $option, $longestWall),
            'categories' => array_map(static fn (object $category): array => [
                'slug' => $category->category_slug,
                'quantity' => (int) $category->quantity,
                'is_required' => (bool) $category->is_required,
            ], $categories),
        ];
    }

    /**
     * @param  array<int, string>  $missing
     */
    private function reason(array $missing, bool $tooBig, object $option, ?int $longestWall): ?string
    {
        if ($tooBig) {
            return sprintf(
                'Bu seçenek en az %d cm duvar ister; odanızın en uzun duvarı %d cm.',
                (int) round((int) $option->min_wall_mm / 10),
                (int) round(($longestWall ?? 0) / 10),
            );
        }

        if ($missing === []) {
            return null;
        }

        // Deliberately not naming the slugs. "tv-unitesi bulunamadı" is a database talking
        // to a customer; they need to know it is the shop, not their room.
        return 'Bu ürün grubunda henüz satıcımız yok.';
    }

    /**
     * The longest wall the room offers, in millimetres.
     *
     * Null when the room is unmeasured, and null means every option is offered — refusing
     * a corner sofa because nobody typed the dimensions would punish the customer for the
     * measurement they were never required to give.
     */
    private function longestWall(Room $room): ?int
    {
        $sides = array_filter([$room->width_mm, $room->length_mm]);

        return $sides === [] ? null : (int) max($sides);
    }
}

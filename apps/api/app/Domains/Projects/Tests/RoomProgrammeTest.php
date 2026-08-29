<?php

declare(strict_types=1);

use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Style;
use App\Domains\Catalog\Services\CatalogCoverage;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\RoomProgrammeReader;
use Database\Seeders\CatalogTaxonomySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RoomProgrammeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The questions a room is designed by.
 *
 * Editorial content rather than logic, which is exactly why it needs a test: a typo in a
 * category slug here is not a crash, it is a question nobody can ever answer — the coverage
 * check finds nothing in `sehpalar`, hides the option, and the customer simply never sees
 * it. Silent, permanent, and invisible in review.
 *
 * That failure has a precedent. The model used to invent the programme, and it invented a
 * television unit, floor-length curtains, a framed picture and four cushions against a
 * catalogue that stocked none of them. The whole point of writing the questions by hand is
 * that a person is accountable for every one naming something real.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogTaxonomySeeder::class);
    $this->seed(RoomProgrammeSeeder::class);
});

it('has a published programme for every room a home has', function (): void {
    $seeded = DB::table('room_programmes')->where('status', 'published')->pluck('room_type')->all();

    // Every room type the product offers, including "other" — a customer who chose it
    // deserves a question too, even if it is only one.
    foreach (RoomType::cases() as $roomType) {
        expect($seeded)->toContain($roomType->value);
    }
});

it('names only categories the catalogue actually has', function (): void {
    $known = Category::query()->pluck('slug')->all();

    $referenced = DB::table('programme_option_categories')
        ->distinct()
        ->pluck('category_slug')
        ->all();

    /*
     * The test that earns this file. An option asking for `sehpalar` instead of `sehpa`
     * is not an error anybody sees: the coverage check finds nothing in a category that
     * does not exist, hides the option, and the question quietly loses an answer for ever.
     */
    // Diffed rather than asserted one by one: `toContain` is variadic, so a second
    // argument meant as a message becomes another value it looks for — which is how the
    // first run of this reported a missing category that was sitting right there.
    expect(array_values(array_diff($referenced, $known)))->toBe([]);
});

it('gives every option an icon, because that is the point', function (): void {
    // People choose furniture by looking at it. An option with no icon is a word on a
    // tile, which is the textarea again in a smaller box.
    $withoutIcon = DB::table('programme_options')->whereNull('icon')->pluck('label')->all();

    expect($withoutIcon)->toBe([]);
});

it('offers a way out of every question that is not required', function (): void {
    /*
     * "Şimdilik istemiyorum" is a real answer and a different thing from an unanswered
     * question. A required question with no none-option and no default is a wizard a
     * customer cannot press through, and pressing through is how most people will use it.
     */
    $questions = DB::table('programme_questions')->where('is_required', true)->get();

    foreach ($questions as $question) {
        $options = DB::table('programme_options')->where('question_id', $question->id)->get();

        $exits = $options->where('is_default', true)->count() + $options->where('is_none', true)->count();

        expect([$question->code => $exits > 0])->toBe([$question->code => true]);
    }
});

it('never marks two defaults for one question', function (): void {
    // Two defaults is no default: the wizard would have to pick one arbitrarily, and
    // which one would depend on row order.
    $offenders = DB::table('programme_options')
        ->select('question_id')
        ->where('is_default', true)
        ->groupBy('question_id')
        ->havingRaw('count(*) > 1')
        ->pluck('question_id')
        ->all();

    expect($offenders)->toBe([]);
});

it('asks nothing at all for an option that means "no thank you"', function (): void {
    // A none-option that still asked the catalogue for a sofa would put furniture in a
    // room the customer explicitly declined.
    $noneOptions = DB::table('programme_options')->where('is_none', true)->pluck('id');

    $asking = DB::table('programme_option_categories')
        ->whereIn('option_id', $noneOptions)
        ->count();

    expect($asking)->toBe(0);
});

it('keeps the question count proportionate to the room', function (): void {
    $counts = DB::table('programme_questions')
        ->join('room_programmes as p', 'p.id', '=', 'programme_questions.programme_id')
        ->groupBy('p.room_type')
        ->selectRaw('p.room_type, count(*) as total')
        ->pluck('total', 'room_type');

    /*
     * A living room has more to decide than a balcony, and the number of questions should
     * say so. A wizard that asks eight questions about a balcony is a wizard people
     * abandon on the balcony — and an abandoned wizard is worse than the textarea it
     * replaced, because at least the textarea could be skipped.
     */
    expect($counts['living_room'])->toBeGreaterThan($counts['balcony'])
        ->and($counts['living_room'])->toBeLessThanOrEqual(8)
        ->and($counts['hallway'])->toBeLessThanOrEqual(5);
});

it('rewrites version one in place rather than stacking versions', function (): void {
    // Re-seeding is a deploy, and a deploy that fixed a typo used to be able to orphan
    // every design that answered the old wording. A genuinely new set of questions is a
    // version bump somebody makes on purpose.
    $before = DB::table('room_programmes')->count();

    $this->seed(RoomProgrammeSeeder::class);

    expect(DB::table('room_programmes')->count())->toBe($before);
});

it('drops a question the editorial decision removed', function (): void {
    DB::table('programme_questions')->insert([
        'id' => (string) Str::uuid7(),
        'programme_id' => DB::table('room_programmes')->where('room_type', 'balcony')->value('id'),
        'code' => 'artik-sorulmayan',
        'prompt' => 'Eski bir soru',
        'kind' => 'single',
        'is_required' => false,
        'position' => 99,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(RoomProgrammeSeeder::class);

    // Merging would have kept it for ever, asking customers something nobody decided to
    // ask any more.
    expect(DB::table('programme_questions')->where('code', 'artik-sorulmayan')->exists())->toBeFalse();
});

describe('the questions as a customer sees them', function (): void {
    beforeEach(function (): void {
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
        $this->room = $this->project->rooms()->firstOrFail();

        $this->room->forceFill([
            'room_type' => 'living_room',
            'width_mm' => 4_000,
            'length_mm' => 5_000,
        ])->save();

        app(CatalogCoverage::class)->forget();
    });

    it('will not offer what the shop has never sold', function (): void {
        /*
         * The rule the whole exercise rests on. A customer offered a television unit by a
         * shop with none will choose it, wait a minute for a render, and be handed a
         * shopping list without it — which is the failure that started this, except back
         * then the model chose the television unit rather than the customer.
         */
        $programme = app(RoomProgrammeReader::class)
            ->forRoom($this->room->fresh(), 'modern');

        $tv = collect($programme['questions'])->firstWhere('code', 'tv-unit');
        $yes = collect($tv['options'])->firstWhere('code', 'yes');

        expect($yes['available'])->toBeFalse()
            // Told as a fact about the shop, not about their room, and without naming a
            // database slug at a customer.
            ->and($yes['unavailable_reason'])->toBe('Bu ürün grubunda henüz satıcımız yok.');
    });

    it('offers what is stocked, and says when it is not the chosen style', function (): void {
        [$seller] = makeApprovedSeller('Program Test A.Ş.', 'program-test');

        $sofa = makeProduct($seller, Category::query()->where('slug', 'kanepe')->firstOrFail(), [
            'name' => 'Modern üçlü kanepe',
            'description' => 'Açık renk kumaş.',
            'price_minor' => 3_200_000,
            'width_mm' => 2_100,
        ]);

        $sofa->styles()->sync([
            Style::query()->where('code', 'modern')->value('id') => [
                'strength_bps' => 10_000, 'is_primary' => true,
            ],
        ]);

        app(CatalogCoverage::class)->forget();

        $reader = app(RoomProgrammeReader::class);

        $inModern = collect($reader->forRoom($this->room->fresh(), 'modern')['questions'])
            ->firstWhere('code', 'seating');
        $inLuxury = collect($reader->forRoom($this->room->fresh(), 'luxury')['questions'])
            ->firstWhere('code', 'seating');

        /*
         * Two different sentences, and the reason this is not a boolean. "We do not sell
         * these" sends a customer to a different plan; "we sell these but not in luxury"
         * sends them to a different style, or to waiting for a seller. Both are still
         * offered — withholding the second would make a thin catalogue look broken.
         */
        expect(collect($inModern['options'])->firstWhere('code', 'three-seater'))
            ->toMatchArray(['available' => true, 'exact_style' => true])
            ->and(collect($inLuxury['options'])->firstWhere('code', 'three-seater'))
            ->toMatchArray(['available' => true, 'exact_style' => false]);
    });

    it('refuses an option the room is too small for, and says by how much', function (): void {
        $this->room->forceFill(['width_mm' => 2_000, 'length_mm' => 2_200])->save();

        $programme = app(RoomProgrammeReader::class)
            ->forRoom($this->room->fresh(), 'modern');

        $corner = collect(collect($programme['questions'])->firstWhere('code', 'seating')['options'])
            ->firstWhere('code', 'corner');

        // Said before it is chosen rather than dropped afterwards by the placement
        // validator, which is a disappointment with a delivery date attached.
        expect($corner['available'])->toBeFalse()
            ->and($corner['unavailable_reason'])->toContain('260 cm')
            ->and($corner['unavailable_reason'])->toContain('220 cm');
    });

    it('offers everything when the room has never been measured', function (): void {
        // Refusing a corner sofa because nobody typed the dimensions would punish the
        // customer for a measurement they were never required to give.
        // Quality goes back to unknown with the numbers. The table refuses a room that
        // claims to be measured and has no measurements, which is the right refusal.
        $this->room->forceFill([
            'width_mm' => null,
            'length_mm' => null,
            'height_mm' => null,
            'measurement_quality' => 'unknown',
        ])->save();

        $programme = app(RoomProgrammeReader::class)
            ->forRoom($this->room->fresh(), null);

        $corner = collect(collect($programme['questions'])->firstWhere('code', 'seating')['options'])
            ->firstWhere('code', 'corner');

        expect($corner['unavailable_reason'])->not->toContain('duvar');
    });

    it('gates an option whose parts are all optional', function (): void {
        /*
         * `is_required` describes what the renderer may drop quietly, not what the wizard
         * may offer. Reading it as the latter made the decor question offer a picture, a
         * plant, a vase and cushions against a catalogue holding none of them — no
         * required categories, so nothing to check, so everything available.
         */
        $programme = app(RoomProgrammeReader::class)
            ->forRoom($this->room->fresh(), 'modern');

        $decor = collect($programme['questions'])->firstWhere('code', 'decor');

        expect(collect($decor['options'])->firstWhere('code', 'plant')['available'])->toBeFalse();
    });

    it('is reachable over HTTP for the room owner', function (): void {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}/programme?style=modern")
            ->assertOk();

        expect($response->json('data.room_type'))->toBe('living_room')
            ->and($response->json('data.questions'))->toHaveCount(8)
            // Every option carries its icon, because choosing by looking is the point.
            ->and($response->json('data.questions.0.options.0.icon'))->not->toBeNull();
    });
});

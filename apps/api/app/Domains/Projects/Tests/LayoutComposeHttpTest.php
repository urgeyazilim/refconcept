<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Enums\MatchStatus;
use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Products\Models\ProductDimension;
use App\Domains\Projects\Enums\DesignVersionStatus;
use App\Domains\Projects\Models\DesignPlan;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomConstraint;
use App\Domains\Projects\Models\RoomGeometryVersion;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * From a design to a room somebody can walk through.
 *
 * The design decides what goes in the room and roughly where, in words. This is the step that
 * turns those words into millimetres and then checks them — against the walls, the doorway,
 * and each other. It is the difference between a picture of a room and a plan of one, and it
 * is where a mistake is a delivery that will not fit rather than an image somebody dislikes.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();

    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    [$this->seller] = makeApprovedSeller('Dizilim A.Ş.', 'dizilim');

    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";

    RoomGeometryVersion::query()->create([
        'room_id' => $this->room->getKey(),
        'version' => 1,
        'source' => 'user',
        'width_mm' => 4_850,
        'length_mm' => 5_200,
        'height_mm' => 2_720,
    ])->forceFill(['is_confirmed' => true, 'confirmed_at' => now()])->save();

    $this->design = $this->room->designs()->create([
        'name' => 'Salon',
        'created_by' => $this->owner->getKey(),
    ]);

    $this->version = $this->design->versions()->create([
        'version_number' => 1,
        'created_by' => $this->owner->getKey(),
    ]);

    $this->version->forceFill(['status' => DesignVersionStatus::Ready, 'completed_at' => now()])->save();
});

/**
 * A product the matcher settled on for a placement.
 *
 * @return array{0: string, 1: string}
 */
function matched(string $name, string $categorySlug, int $width, int $depth, int $placementIndex, int $rank = 1): array
{
    $category = Category::query()->firstWhere('slug', $categorySlug)
        ?? makeCategory(ucfirst($categorySlug), $categorySlug, 'living_room');

    $product = makeProduct(test()->seller, $category, [
        'name' => $name,
        'price_minor' => 100_000,
        'width_mm' => $width,
    ]);

    $sku = $product->skus->firstOrFail();

    ProductDimension::query()->updateOrCreate(
        ['sku_id' => $sku->getKey()],
        ['width_mm' => $width, 'height_mm' => 800, 'depth_mm' => $depth],
    );

    $match = DesignMatch::query()->create([
        'design_version_id' => test()->version->getKey(),
        'placement_index' => $placementIndex,
        'placement_category' => $categorySlug,
        'product_id' => $product->getKey(),
        'sku_id' => $sku->getKey(),
        'rank' => $rank,
        'score_bps' => 9_000 - $rank * 100,
        'price_minor' => 100_000,
        'currency' => 'TRY',
    ]);

    // Not mass assignable: a status is set by accepting or rejecting a suggestion, so the
    // fixture takes the same route the matcher does.
    $match->forceFill(['status' => MatchStatus::Suggested])->save();

    return [$product->getKey(), $sku->getKey()];
}

/** @param  list<array<string, mixed>>  $placements */
function planned(array $placements): void
{
    DesignPlan::query()->create([
        'design_version_id' => test()->version->getKey(),
        'style' => 'İskandinav',
        'placements' => $placements,
    ]);
}

it('arranges what the design settled on, and nothing else', function (): void {
    planned([
        ['category' => 'kanepe', 'wall' => 'kuzey duvarı', 'max_width_mm' => 2_200],
        ['category' => 'sehpa', 'wall' => null, 'max_width_mm' => 1_000],
    ]);

    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);
    matched('Orta sehpa', 'sehpa', 900, 900, 1);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/compose")
        ->assertOk();

    $items = $response->json('data.items');

    // Two placements, two pieces. Nothing invented, nothing dropped.
    expect($items)->toHaveCount(2)
        // "kuzey duvarı" is what the plan says, because the model is asked in Turkish. The
        // composer works in the four words the geometry uses, and the translation happens
        // once rather than at every comparison.
        ->and(collect($items)->firstWhere('category', 'kanepe')['position_z_mm'])->toBeLessThan(1_000);
});

it('produces a layout the room actually accepts', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'east',
        'offset_mm' => 400,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    planned([
        ['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200],
        ['category' => 'sehpa', 'max_width_mm' => 1_000],
        ['category' => 'hali', 'max_width_mm' => 2_400],
        ['category' => 'konsol', 'wall' => 'güney', 'max_width_mm' => 1_400],
        ['category' => 'kitaplik', 'wall' => 'doğu', 'max_width_mm' => 1_200],
        ['category' => 'tablo', 'wall' => 'güney', 'max_width_mm' => 900],
    ]);

    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);
    matched('Orta sehpa', 'sehpa', 900, 900, 1);
    matched('Halı', 'hali', 2_400, 1_700, 2);
    matched('Konsol', 'konsol', 1_400, 420, 3);
    matched('Kitaplık', 'kitaplik', 1_200, 350, 4);
    matched('Tablo', 'tablo', 900, 50, 5);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/compose")
        ->assertOk();

    /*
     * The strongest assertion in this file, and the reason the composer exists rather than
     * the coordinates coming from the model: every position it produced is checked by the
     * same rules that check a customer's own drag, and none of them is refused. A layout
     * engine that produces blocked positions is a layout engine that has moved the problem.
     */
    expect($response->json('data.has_collisions'))->toBeFalse()
        ->and(collect($response->json('data.items'))->pluck('collision_state')->unique()->all())
        ->toBe(['ok']);
});

it('places one product per placement rather than every suggestion', function (): void {
    planned([['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200]]);

    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0, rank: 1);
    matched('Gri kanepe', 'kanepe', 2_050, 900, 0, rank: 2);
    matched('Bej kanepe', 'kanepe', 2_100, 900, 0, rank: 3);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/compose")
        ->assertOk();

    // The matcher offers several, ranked. Placing all of them is how a room ends up with
    // three sofas in it.
    expect($response->json('data.items'))->toHaveCount(1);
});

it('refuses to overwrite an arrangement somebody made, until asked twice', function (): void {
    planned([['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200]]);

    [$productId, $skuId] = matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);

    $this->actingAs($this->owner)->putJson("{$this->url}/layout", [
        'items' => [[
            'product_id' => $productId,
            'sku_id' => $skuId,
            'position_x_mm' => 2_400,
            'position_z_mm' => 4_000,
        ]],
    ])->assertOk();

    // Somebody spent ten minutes moving furniture. Pressing the wrong button should get them
    // a question, not their afternoon back in the shape the engine likes.
    $this->actingAs($this->owner)->postJson("{$this->url}/layout/compose")->assertStatus(409);

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/compose", ['replace' => true])
        ->assertOk();

    expect($response->json('data.items.0.position_z_mm'))->not->toBe(4_000);
});

it('says which products it could not measure rather than guessing their size', function (): void {
    planned([
        ['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200],
        ['category' => 'aydinlatma', 'max_width_mm' => 400],
    ]);

    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);

    [$lampProduct] = matched('Zemin lambası', 'aydinlatma', 400, 400, 1);

    // The seller never filled the dimensions in. A box at an invented size is a promise that
    // it fits, made on no evidence, to somebody about to pay for a delivery.
    ProductDimension::query()->where('sku_id', function ($query) use ($lampProduct): void {
        $query->select('id')->from('product_skus')->where('product_id', $lampProduct);
    })->delete();

    $response = $this->actingAs($this->owner)
        ->postJson("{$this->url}/layout/compose")
        ->assertOk();

    expect($response->json('data.items'))->toHaveCount(1)
        ->and($response->json('meta.unmeasured'))->toHaveCount(1);
});

it('takes the proposed measurements as agreed rather than refusing to arrange', function (): void {
    /*
     * "Kullanıcıyı hiçbir şeyle uğraştırmak istemiyorum." A room whose measurements were
     * read and never confirmed used to be refused; now the proposal is taken as agreed so
     * the room can be furnished, and the guide keeps asking "doğru mu?" until somebody says.
     */
    RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->update(['is_confirmed' => false]);

    planned([['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200]]);
    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);

    $this->actingAs($this->owner)->postJson("{$this->url}/layout/compose")->assertOk();

    expect(RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->where('is_confirmed', true)->exists())->toBeTrue();
});

it('will not arrange a room nobody has read or measured', function (): void {
    RoomGeometryVersion::query()->where('room_id', $this->room->getKey())->delete();

    planned([['category' => 'kanepe', 'wall' => 'kuzey', 'max_width_mm' => 2_200]]);
    matched('Üçlü kanepe', 'kanepe', 2_200, 900, 0);

    $this->actingAs($this->owner)->postJson("{$this->url}/layout/compose")->assertStatus(422);
});

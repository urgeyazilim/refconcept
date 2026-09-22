<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomConstraint;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Which kind of door or window it is.
 *
 * A window was a hole with a sill; a customer's window is a double casement or a French
 * balcony. The kind is recorded on the opening, has to be one its type can be, and reaches
 * the plan in the same rows as everything else.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->owner = User::factory()->create();
    $this->project = Project::factory()->ownedBy($this->owner)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();
    $this->url = "/api/v1/projects/{$this->project->getKey()}/rooms/{$this->room->getKey()}";
});

it('records which kind of window it is', function (): void {
    $response = $this->actingAs($this->owner)->postJson("{$this->url}/constraints", [
        'type' => 'window',
        'variant' => 'double',
        'wall' => 'north',
        'offset_mm' => 1_000,
        'width_mm' => 1_400,
        'height_mm' => 1_400,
        'sill_height_mm' => 900,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.variant', 'double')
        ->assertJsonPath('data.variant_label', 'Çift kanat pencere')
        ->assertJsonPath('data.description', 'Çift kanat pencere · 140 cm genişlik · yerden 90 cm');
});

it('refuses a kind the type cannot be', function (): void {
    // A sliding window is not a thing; drawing one would be a room the customer does not have.
    $this->actingAs($this->owner)->postJson("{$this->url}/constraints", [
        'type' => 'window',
        'variant' => 'sliding',
        'wall' => 'north',
        'offset_mm' => 1_000,
        'width_mm' => 1_400,
    ])->assertUnprocessable()->assertJsonValidationErrors(['variant']);
});

it('lets a single door become a double, and not a triple', function (): void {
    $door = RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'variant' => 'single_door',
        'wall' => 'east',
        'offset_mm' => 400,
        'width_mm' => 1_600,
        'height_mm' => 2_100,
    ]);

    $this->actingAs($this->owner)->patchJson("{$this->url}/constraints/{$door->getKey()}", ['variant' => 'double_door'])
        ->assertOk()
        ->assertJsonPath('data.variant', 'double_door');

    // The kind has to fit the type the opening already has, not one the request happens to omit.
    $this->actingAs($this->owner)->patchJson("{$this->url}/constraints/{$door->getKey()}", ['variant' => 'triple'])
        ->assertUnprocessable()->assertJsonValidationErrors(['variant']);
});

it('hands the plan the kind with the opening', function (): void {
    RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'balcony_door',
        'variant' => 'sliding',
        'wall' => 'south',
        'offset_mm' => 1_000,
        'width_mm' => 2_400,
        'height_mm' => 2_200,
        'sill_height_mm' => 0,
    ]);

    $this->actingAs($this->owner)->getJson("{$this->url}/layout")
        ->assertOk()
        ->assertJsonPath('data.openings.0.variant', 'sliding');
});

it('records which jamb a door hangs on and which way it opens', function (): void {
    $door = RoomConstraint::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'door',
        'wall' => 'north',
        'offset_mm' => 400,
        'width_mm' => 900,
        'height_mm' => 2_100,
    ]);

    // Moved to the other jamb, opening out of the room: the quarter of floor it swept is free.
    $this->actingAs($this->owner)->patchJson("{$this->url}/constraints/{$door->getKey()}", ['swing' => 'end_out'])
        ->assertOk()
        ->assertJsonPath('data.swing', 'end_out');

    $this->actingAs($this->owner)->getJson("{$this->url}/layout")
        ->assertOk()
        ->assertJsonPath('data.openings.0.swing', 'end_out');
});

it('lets a window say which way it opens', function (): void {
    /*
     * A window opens somewhere too.
     *
     * It sweeps no floor, so it was never asked — but "can I open this once the sofa is
     * there" is a question about the room, and a casement swinging inward over a console
     * table is a window nobody opens.
     */
    $this->actingAs($this->owner)->postJson("{$this->url}/constraints", [
        'type' => 'window',
        'swing' => 'start_in',
        'wall' => 'north',
        'offset_mm' => 1_000,
        'width_mm' => 1_400,
    ])->assertCreated()->assertJsonPath('data.swing', 'start_in');
});

it('lets a window be top-hung and refuses a door that claims to be', function (): void {
    // A vasistas: hinged along its top edge, no jamb, over a door or behind a counter.
    $this->actingAs($this->owner)->postJson("{$this->url}/constraints", [
        'type' => 'window',
        'swing' => 'top_hung',
        'wall' => 'north',
        'offset_mm' => 2_000,
        'width_mm' => 700,
        'height_mm' => 500,
        'sill_height_mm' => 1_800,
    ])->assertCreated()->assertJsonPath('data.swing', 'top_hung');

    // A doorway with a fanlight over it is two openings, not one door that opens two ways.
    $this->actingAs($this->owner)->postJson("{$this->url}/constraints", [
        'type' => 'door',
        'swing' => 'top_hung',
        'wall' => 'east',
        'offset_mm' => 400,
        'width_mm' => 900,
    ])->assertUnprocessable()->assertJsonValidationErrors(['swing']);
});

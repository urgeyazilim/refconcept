<?php

declare(strict_types=1);

use App\Domains\Credits\Enums\CreditLotSource;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\RoomMedia;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The customer screen support uses, and the line it must not cross.
 *
 * Most of what is worth asserting here is about who may look. The screen exists so somebody
 * answering the phone can see an account; the same screen can hand back a photograph of the
 * inside of that person's home, and the whole justification for that is the audit entry
 * attached to it. A version of this that shows the picture and forgets the entry is worse
 * than no screen at all, because it looks careful.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('s3');

    $this->operator = User::factory()->create();
    // Operator rather than super-admin: the point is that the permission carries this
    // screen, not that somebody with every permission can open it.
    grantPlatformRole($this->operator, SystemRole::Operator);

    $this->customer = User::factory()->create(['email' => 'alici@ornek.test']);

    app(CreditLedger::class)->grant($this->customer, 120, CreditLotSource::Purchase, 'Test paketi');

    $this->project = Project::factory()->ownedBy($this->customer)->withRoom()->create();
    $this->room = $this->project->rooms()->firstOrFail();

    $this->photo = RoomMedia::query()->create([
        'room_id' => $this->room->getKey(),
        'type' => 'photo',
        'disk' => 's3',
        'storage_path' => 'room-media/'.$this->room->getKey().'/'.Str::uuid7().'.jpg',
        'original_name' => 'salon.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 240_000,
        'width' => 2_048,
        'height' => 1_536,
        'checksum_sha256' => hash('sha256', 'salon'),
        'position' => 0,
    ]);
});

it('lists customers with what support actually asks about', function (): void {
    $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonPath('data.0.email', 'alici@ornek.test')
        ->assertJsonPath('data.0.credit_balance', 120)
        ->assertJsonPath('data.0.project_count', 1);
});

it('finds a customer by the address they gave on the phone', function (): void {
    User::factory()->create(['email' => 'baskasi@ornek.test']);

    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/customers?search=alici')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.email'))->toBe('alici@ornek.test');
});

it('shows one customer with their orders, credits and projects together', function (): void {
    $response = $this->actingAs($this->operator)
        ->getJson("/api/v1/admin/customers/{$this->customer->getKey()}")
        ->assertOk();

    // The question is almost never about one of these alone — "I paid and my design did not
    // appear" spans all three.
    expect($response->json('data.credits.balance'))->toBe(120)
        ->and($response->json('data.projects'))->toHaveCount(1)
        ->and($response->json('data.orders'))->toBe([]);
});

it('never puts a photograph in the list or the detail', function (): void {
    $list = $this->actingAs($this->operator)->getJson('/api/v1/admin/customers')->assertOk();
    $detail = $this->actingAs($this->operator)
        ->getJson("/api/v1/admin/customers/{$this->customer->getKey()}")
        ->assertOk();

    /*
     * Browsing is not a reason to see anybody's home. A thumbnail beside each row would make
     * looking the default rather than a decision somebody has to make and justify.
     */
    expect(json_encode($list->json()))->not->toContain('room-media')
        ->and(json_encode($detail->json()))->not->toContain('room-media');
});

it('writes down who looked at a room photograph, and why', function (): void {
    $this->actingAs($this->operator)
        ->postJson(
            "/api/v1/admin/customers/{$this->customer->getKey()}/media/{$this->photo->getKey()}",
            ['kind' => 'room', 'reason' => 'Müşteri tasarımın bozuk geldiğini bildirdi.'],
        )
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_in']]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'administration.customer.media_viewed',
        'actor_id' => $this->operator->getKey(),
    ]);
});

it('refuses to hand over a photograph without a reason', function (): void {
    // The reason is the whole point of the audit entry. An empty one turns the log into a
    // list of timestamps nobody can act on.
    $this->actingAs($this->operator)
        ->postJson(
            "/api/v1/admin/customers/{$this->customer->getKey()}/media/{$this->photo->getKey()}",
            ['kind' => 'room'],
        )
        ->assertStatus(422);
});

it('keeps the object key out of the audit log', function (): void {
    $this->actingAs($this->operator)
        ->postJson(
            "/api/v1/admin/customers/{$this->customer->getKey()}/media/{$this->photo->getKey()}",
            ['kind' => 'room', 'reason' => 'Destek talebi 4821 inceleniyor.'],
        )
        ->assertOk();

    /*
     * The audit log is read by more people than this endpoint is called by, and an object
     * key is most of a link. Recording it would move the photograph somewhere with a wider
     * audience than the screen that was so careful about it.
     */
    $entry = DB::table('audit_logs')->where('action', 'administration.customer.media_viewed')->first();

    expect(json_encode($entry))->not->toContain($this->photo->storage_path);
});

it('will not serve one customer photograph from another customer page', function (): void {
    $stranger = User::factory()->create();

    // Two ids in one path is two chances to swap one, and the pairing is what makes the
    // audit entry mean anything.
    $this->actingAs($this->operator)
        ->postJson(
            "/api/v1/admin/customers/{$stranger->getKey()}/media/{$this->photo->getKey()}",
            ['kind' => 'room', 'reason' => 'Yanlış eşleşme denemesi.'],
        )
        ->assertNotFound();
});

it('keeps staff without the permission out entirely', function (): void {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->getJson('/api/v1/admin/customers')->assertForbidden();

    $this->actingAs($nobody)
        ->postJson(
            "/api/v1/admin/customers/{$this->customer->getKey()}/media/{$this->photo->getKey()}",
            ['kind' => 'room', 'reason' => 'Yetkisiz deneme.'],
        )
        ->assertForbidden();
});

it('keeps a customer out of the customer list', function (): void {
    // The screen is for staff. A signed-in customer reaching it would be reading everybody
    // else's account.
    $this->actingAs($this->customer)->getJson('/api/v1/admin/customers')->assertForbidden();
});

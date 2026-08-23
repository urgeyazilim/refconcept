<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function addressPayload(array $overrides = []): array
{
    return array_merge([
        'label' => 'Ev',
        'recipient_name' => 'Deniz Yılmaz',
        'phone' => '+905550000000',
        'city' => 'İstanbul',
        'district' => 'Kadıköy',
        'address_line1' => 'Bağdat Caddesi 100',
        'postal_code' => '34710',
    ], $overrides);
}

it('returns the signed-in profile', function (): void {
    $user = User::factory()->withProfile('Deniz', 'Yılmaz')->create();

    $this->actingAs($user)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.profile.display_name', 'Deniz Yılmaz');
});

it('updates profile fields', function (): void {
    $user = User::factory()->withProfile()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', [
            'first_name' => 'Ayşe',
            'last_name' => 'Demir',
            'marketing_opt_in' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.profile.first_name', 'Ayşe')
        ->assertJsonPath('data.profile.marketing_opt_in', true);
});

it('does not let the profile endpoint change the e-mail or status', function (): void {
    $user = User::factory()->withProfile()->create(['email' => 'sabit@example.com']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', [
            'first_name' => 'Ayşe',
            'email' => 'saldirgan@example.com',
            'status' => 'banned',
        ])
        ->assertOk();

    $user->refresh();

    // Changing an address must go through re-verification, and status is an
    // administrative action with its own authorization and audit trail.
    expect($user->email)->toBe('sabit@example.com')
        ->and($user->status->value)->toBe('active');
});

it('rejects an unauthenticated profile read', function (): void {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});

it('creates an address and makes the first one the default', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/addresses', addressPayload())
        ->assertCreated();

    expect($response->json('data.is_default_shipping'))->toBeTrue()
        ->and($response->json('data.is_default_billing'))->toBeTrue();
});

it('moves the default flag rather than allowing two defaults', function (): void {
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson('/api/v1/addresses', addressPayload())->json('data.id');

    $second = $this->actingAs($user)
        ->postJson('/api/v1/addresses', addressPayload([
            'label' => 'İş',
            'is_default_shipping' => true,
        ]))
        ->json('data.id');

    // A partial unique index enforces this in the database as well; if the controller
    // failed to clear the old default the insert itself would fail.
    expect(UserAddress::query()->find($first)->is_default_shipping)->toBeFalse()
        ->and(UserAddress::query()->find($second)->is_default_shipping)->toBeTrue()
        ->and(UserAddress::query()->where('user_id', $user->getKey())->where('is_default_shipping', true)->count())->toBe(1);
});

it('lists only the signed-in user addresses', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/addresses', addressPayload())->assertCreated();
    $this->actingAs($other)->postJson('/api/v1/addresses', addressPayload(['label' => 'Başkası']))->assertCreated();

    $response = $this->actingAs($user)->getJson('/api/v1/addresses')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.label'))->toBe('Ev');
});

it('refuses to read, update or delete somebody else address', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $id = $this->actingAs($owner)->postJson('/api/v1/addresses', addressPayload())->json('data.id');

    $this->actingAs($stranger)->getJson("/api/v1/addresses/{$id}")->assertForbidden();
    $this->actingAs($stranger)->patchJson("/api/v1/addresses/{$id}", ['city' => 'Ankara'])->assertForbidden();
    $this->actingAs($stranger)->deleteJson("/api/v1/addresses/{$id}")->assertForbidden();

    expect(UserAddress::query()->find($id)->city)->toBe('İstanbul');
});

it('soft deletes an address so order history stays explainable', function (): void {
    $user = User::factory()->create();

    $id = $this->actingAs($user)->postJson('/api/v1/addresses', addressPayload())->json('data.id');

    $this->actingAs($user)->deleteJson("/api/v1/addresses/{$id}")->assertOk();

    expect(UserAddress::query()->find($id))->toBeNull()
        ->and(UserAddress::withTrashed()->find($id))->not->toBeNull();
});

it('requires a verified e-mail before using the address book', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/addresses')
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');
});

it('validates required address fields', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/addresses', ['label' => 'Eksik'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_name', 'city', 'address_line1']);
});

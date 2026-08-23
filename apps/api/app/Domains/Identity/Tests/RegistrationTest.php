<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\ConsentType;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\EmailVerificationToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    // The breach check calls an external API; the suite must not depend on the network.
    config()->set('refconcept.security.password.check_compromised', false);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'yeni@example.com',
        'password' => 'CokGuvenliParola1',
        'password_confirmation' => 'CokGuvenliParola1',
        'first_name' => 'Deniz',
        'last_name' => 'Yılmaz',
        'consents' => [
            ['type' => 'privacy_notice', 'version' => '2026-01'],
            ['type' => 'terms', 'version' => '2026-01'],
        ],
    ], $overrides);
}

it('creates an account, a profile and consent records', function (): void {
    $response = $this->postJson('/api/v1/auth/register', validRegistrationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.email', 'yeni@example.com')
        ->assertJsonPath('data.status', UserStatus::PendingVerification->value);

    $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();

    expect($user->profile)->not->toBeNull()
        ->and($user->profile->first_name)->toBe('Deniz')
        ->and($user->consents()->count())->toBe(2);
});

it('never returns the password hash', function (): void {
    $response = $this->postJson('/api/v1/auth/register', validRegistrationPayload());

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('password_hash')
        ->not->toContain('CokGuvenliParola1');
});

it('does not issue a token before the address is verified', function (): void {
    $response = $this->postJson('/api/v1/auth/register', validRegistrationPayload());

    expect($response->json('data.token'))->toBeNull()
        ->and(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('token');
});

it('sends a verification e-mail and stores only its hash', function (): void {
    $this->postJson('/api/v1/auth/register', validRegistrationPayload())->assertCreated();

    $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmailNotification::class);

    $token = EmailVerificationToken::query()->where('user_id', $user->getKey())->firstOrFail();

    // 64 hex characters is a SHA-256 digest; a plaintext token would be 64 random
    // alphanumerics, so length alone is not enough to tell them apart.
    expect($token->token_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('rejects registration without the mandatory consents', function (): void {
    $payload = validRegistrationPayload([
        'consents' => [['type' => 'marketing', 'version' => '2026-01']],
    ]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('consents');

    expect(User::query()->where('email', 'yeni@example.com')->exists())->toBeFalse();
});

it('rejects registration when a required consent is explicitly refused', function (): void {
    $payload = validRegistrationPayload([
        'consents' => [
            ['type' => 'privacy_notice', 'version' => '2026-01', 'granted' => false],
            ['type' => 'terms', 'version' => '2026-01'],
        ],
    ]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('consents');
});

it('accepts optional marketing consent alongside the mandatory ones', function (): void {
    $payload = validRegistrationPayload([
        'consents' => [
            ['type' => 'privacy_notice', 'version' => '2026-01'],
            ['type' => 'terms', 'version' => '2026-01'],
            ['type' => 'marketing', 'version' => '2026-01'],
        ],
    ]);

    $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

    $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();

    expect($user->consents()->where('type', ConsentType::Marketing->value)->exists())->toBeTrue();
});

it('rejects a weak password', function (): void {
    $payload = validRegistrationPayload([
        'password' => 'sifre',
        'password_confirmation' => 'sifre',
    ]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('rejects a duplicate e-mail regardless of casing', function (): void {
    User::factory()->create(['email' => 'mevcut@example.com']);

    $payload = validRegistrationPayload(['email' => 'MEVCUT@example.com']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('grants no roles to a freshly registered account', function (): void {
    $this->postJson('/api/v1/auth/register', validRegistrationPayload())->assertCreated();

    $user = User::query()->where('email', 'yeni@example.com')->firstOrFail();

    // Seller rights come only from an approved application, never from registration.
    expect($user->roleGrants()->count())->toBe(0);
});

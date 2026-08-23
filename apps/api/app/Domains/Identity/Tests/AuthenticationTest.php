<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\LoginAttempt;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;

it('issues a token for valid credentials', function (): void {
    $user = User::factory()->create(['email' => 'giris@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'giris@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
        'device_name' => 'pest',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.id', $user->getKey());

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.expires_at'))->not->toBeNull();
});

it('records a session and stamps the last login', function (): void {
    $user = User::factory()->create(['email' => 'giris@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'giris@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
        'device_name' => 'pest',
    ])->assertOk();

    $session = UserSession::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($session->device_name)->toBe('pest')
        ->and($session->ended_at)->toBeNull()
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password without revealing which field was wrong', function (): void {
    User::factory()->create(['email' => 'giris@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'giris@example.com',
        'password' => 'yanlis-parola',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');

    expect($response->json('errors.email.0'))->toContain('hatalı');
});

it('answers identically for an unknown account', function (): void {
    User::factory()->create(['email' => 'giris@example.com']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'giris@example.com',
        'password' => 'yanlis-parola',
    ]);

    $unknownAccount = $this->postJson('/api/v1/auth/login', [
        'email' => 'yok@example.com',
        'password' => 'yanlis-parola',
    ]);

    // Any difference here turns the login form into an account-enumeration oracle.
    expect($unknownAccount->status())->toBe($wrongPassword->status())
        ->and($unknownAccount->json('errors.email'))->toBe($wrongPassword->json('errors.email'));
});

it('records every attempt, including ones against accounts that do not exist', function (): void {
    User::factory()->create(['email' => 'giris@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'giris@example.com',
        'password' => 'yanlis-parola',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'hicyok@example.com',
        'password' => 'yanlis-parola',
    ]);

    expect(LoginAttempt::query()->count())->toBe(2)
        ->and(LoginAttempt::query()->where('successful', true)->count())->toBe(0)
        ->and(LoginAttempt::query()->where('identifier', 'hicyok@example.com')->exists())->toBeTrue();
});

it('refuses a suspended account with its own message', function (): void {
    User::factory()->suspended()->create(['email' => 'askida@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'askida@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ]);

    $response->assertStatus(422);

    expect($response->json('errors.email.0'))->toContain('askıya');
});

it('refuses a banned account', function (): void {
    User::factory()->banned()->create(['email' => 'engelli@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'engelli@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->assertStatus(422);
});

it('lets an unverified account sign in so it can finish verification', function (): void {
    User::factory()->unverified()->create(['email' => 'bekleyen@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'bekleyen@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->assertOk();
});

it('matches the e-mail case-insensitively', function (): void {
    User::factory()->create(['email' => 'karisik@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'KaRiSiK@Example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->assertOk();
});

it('returns the signed-in user from /auth/me', function (): void {
    $user = User::factory()->withProfile('Deniz', 'Yılmaz')->create();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->getKey())
        ->assertJsonPath('data.profile.first_name', 'Deniz');
});

it('rejects unauthenticated access to /auth/me', function (): void {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('revokes the current token on logout', function (): void {
    $user = User::factory()->create(['email' => 'cikis@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'cikis@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);

    // One application instance serves every request in a test process, so the guard
    // still holds the user it resolved for the logout call. A real client opens a new
    // request; forgetting the guards reproduces that.
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('ends the session row on logout', function (): void {
    $user = User::factory()->create(['email' => 'cikis@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'cikis@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    $session = UserSession::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($session->ended_at)->not->toBeNull()
        ->and($session->ended_reason)->toBe('logout');
});

it('blocks a token issued before the account was suspended', function (): void {
    $user = User::factory()->create(['email' => 'sonra-askiya@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'sonra-askiya@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->json('data.token');

    $user->forceFill(['status' => UserStatus::Suspended])->save();

    // Without this check a suspension would only bite once the token expired.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
});

it('ends every session with logout-all', function (): void {
    $user = User::factory()->create(['email' => 'coklu@example.com']);

    $first = $this->postJson('/api/v1/auth/login', [
        'email' => 'coklu@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
        'device_name' => 'laptop',
    ])->json('data.token');

    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'coklu@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
        'device_name' => 'telefon',
    ])->json('data.token');

    $this->withToken($second)->postJson('/api/v1/auth/logout-all')->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);

    Auth::forgetGuards();

    $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken($second)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

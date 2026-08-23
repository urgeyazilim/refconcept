<?php

declare(strict_types=1);

use App\Domains\Identity\Models\PasswordResetToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\ResetPasswordNotification;
use App\Domains\Identity\Services\PasswordResetService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('sends a reset link to a registered address', function (): void {
    $user = User::factory()->create(['email' => 'unuttum@example.com']);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unuttum@example.com'])
        ->assertOk();

    Notification::assertSentTo($user, ResetPasswordNotification::class);

    expect(PasswordResetToken::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('answers identically for an address that is not registered', function (): void {
    User::factory()->create(['email' => 'var@example.com']);

    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'var@example.com']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'yok@example.com']);

    // Any difference makes this endpoint an account-enumeration oracle.
    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json('message'))->toBe($known->json('message'));

    Notification::assertCount(1);
});

it('changes the password when the token is redeemed', function (): void {
    $user = User::factory()->create(['email' => 'sifirla@example.com']);
    $token = issueResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'YepyeniParola2026',
        'password_confirmation' => 'YepyeniParola2026',
    ])->assertOk();

    expect(Hash::check('YepyeniParola2026', (string) $user->fresh()->password_hash))->toBeTrue();
});

it('lets the user sign in with the new password and not the old one', function (): void {
    $user = User::factory()->create(['email' => 'sifirla@example.com']);
    $token = issueResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'YepyeniParola2026',
        'password_confirmation' => 'YepyeniParola2026',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'sifirla@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->assertStatus(422);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'sifirla@example.com',
        'password' => 'YepyeniParola2026',
    ])->assertOk();
});

it('revokes every existing session when the password is reset', function (): void {
    $user = User::factory()->create(['email' => 'sifirla@example.com']);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'sifirla@example.com',
        'password' => UserFactory::DEFAULT_PASSWORD,
    ])->json('data.token');

    $resetToken = issueResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $resetToken,
        'password' => 'YepyeniParola2026',
        'password_confirmation' => 'YepyeniParola2026',
    ])->assertOk();

    // A reset usually means the old credential is compromised; leaving live sessions
    // in place would defeat the point of resetting at all.
    expect($user->fresh()->tokens()->count())->toBe(0);

    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($user->sessions()->whereNull('ended_at')->count())->toBe(0)
        ->and($user->sessions()->where('ended_reason', 'password_reset')->count())->toBe(1);
});

it('refuses to reuse a reset token', function (): void {
    $user = User::factory()->create();
    $token = issueResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'YepyeniParola2026',
        'password_confirmation' => 'YepyeniParola2026',
    ])->assertOk();

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'BaskaParola2026xy',
        'password_confirmation' => 'BaskaParola2026xy',
    ])->assertStatus(422)->assertJsonValidationErrors('token');
});

it('refuses an expired reset token', function (): void {
    $user = User::factory()->create();
    $token = issueResetToken($user);

    PasswordResetToken::query()
        ->where('user_id', $user->getKey())
        ->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'YepyeniParola2026',
        'password_confirmation' => 'YepyeniParola2026',
    ])->assertStatus(422)->assertJsonValidationErrors('token');

    expect(Hash::check(UserFactory::DEFAULT_PASSWORD, (string) $user->fresh()->password_hash))->toBeTrue();
});

it('enforces the password policy on reset', function (): void {
    $user = User::factory()->create();
    $token = issueResetToken($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'kisa',
        'password_confirmation' => 'kisa',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('stores only the hash of a reset token', function (): void {
    $user = User::factory()->create();
    $token = issueResetToken($user);

    $stored = PasswordResetToken::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->token_hash)->not->toBe($token);
});

/**
 * Issues a reset token and returns its plaintext by intercepting the notification.
 */
function issueResetToken(User $user): string
{
    app(PasswordResetService::class)->request((string) $user->email);

    $captured = null;

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$captured): bool {
        $captured = (new ReflectionProperty($notification, 'token'))->getValue($notification);

        return true;
    });

    return (string) $captured;
}

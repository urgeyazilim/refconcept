<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\EmailVerificationToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\EmailVerificationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('activates a pending account when the token is redeemed', function (): void {
    $user = User::factory()->unverified()->create();

    $token = app(EmailVerificationService::class)->issue($user);

    $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    $user->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active);
});

it('refuses to reuse a token', function (): void {
    $user = User::factory()->unverified()->create();
    $token = app(EmailVerificationService::class)->issue($user);

    $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();

    $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');
});

it('refuses an expired token', function (): void {
    $user = User::factory()->unverified()->create();
    $token = app(EmailVerificationService::class)->issue($user);

    EmailVerificationToken::query()
        ->where('user_id', $user->getKey())
        ->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('refuses an unknown token', function (): void {
    $this->postJson('/api/v1/auth/email/verify', ['token' => str_repeat('a', 64)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');
});

it('answers identically for unknown, expired and reused tokens', function (): void {
    $user = User::factory()->unverified()->create();
    $used = app(EmailVerificationService::class)->issue($user);
    $this->postJson('/api/v1/auth/email/verify', ['token' => $used])->assertOk();

    $reused = $this->postJson('/api/v1/auth/email/verify', ['token' => $used]);
    $unknown = $this->postJson('/api/v1/auth/email/verify', ['token' => str_repeat('b', 64)]);

    // Telling these apart would confirm which tokens exist.
    expect($unknown->json('errors.token'))->toBe($reused->json('errors.token'));
});

it('invalidates the previous token when a new one is issued', function (): void {
    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationService::class);

    $first = $service->issue($user);
    $second = $service->issue($user);

    // A user who requested a fresh link must not still have a working old one.
    $this->postJson('/api/v1/auth/email/verify', ['token' => $first])->assertStatus(422);
    $this->postJson('/api/v1/auth/email/verify', ['token' => $second])->assertOk();
});

it('refuses a token issued for an address the account no longer uses', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'eski@example.com']);
    $token = app(EmailVerificationService::class)->issue($user);

    $user->forceFill(['email' => 'yeni@example.com'])->save();

    // Otherwise an e-mail delivered to the old address would verify the new one.
    $this->postJson('/api/v1/auth/email/verify', ['token' => $token])
        ->assertStatus(422);

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('never stores the plaintext token', function (): void {
    $user = User::factory()->unverified()->create();
    $token = app(EmailVerificationService::class)->issue($user);

    $stored = EmailVerificationToken::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($stored->token_hash)->not->toBe($token)
        ->and($stored->token_hash)->toBe(hash('sha256', $token));
});

it('lets an authenticated unverified account request a new e-mail', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/email/resend')
        ->assertOk();

    expect(EmailVerificationToken::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('does not issue another e-mail to an already verified account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/email/resend')
        ->assertOk()
        ->assertJsonPath('message', 'E-posta adresiniz zaten doğrulanmış.');

    expect(EmailVerificationToken::query()->where('user_id', $user->getKey())->count())->toBe(0);
});

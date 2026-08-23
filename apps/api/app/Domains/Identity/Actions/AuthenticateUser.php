<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\DTOs\AuthenticationResult;
use App\Domains\Identity\DTOs\LoginData;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Exceptions\AuthenticationFailed;
use App\Domains\Identity\Models\LoginAttempt;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies credentials and issues an API token.
 *
 * Every attempt — successful or not, for an existing account or not — is written to
 * `login_attempts`. That table is what turns "a user forgot their password" and
 * "someone is spraying credentials" into distinguishable events.
 */
final class AuthenticateUser
{
    public function __construct(private readonly int $tokenTtlDays = 30) {}

    /**
     * @throws AuthenticationFailed
     */
    public function execute(LoginData $data): AuthenticationResult
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $data->email)->first();

        try {
            $this->assertCredentials($user, $data->password);
        } catch (AuthenticationFailed $e) {
            $this->recordAttempt($data, $user, successful: false, reason: $e->reason);

            throw $e;
        }

        /** @var User $user */
        return DB::transaction(function () use ($user, $data): AuthenticationResult {
            $expiresAt = now()->addDays($this->tokenTtlDays);

            $newToken = $user->createToken(
                name: $data->deviceName ?? 'api',
                abilities: ['*'],
                expiresAt: $expiresAt,
            );

            $newToken->accessToken->forceFill([
                'created_ip' => $data->ipAddress,
                'user_agent' => $this->truncate($data->userAgent),
            ])->save();

            $session = UserSession::query()->create([
                'user_id' => $user->getKey(),
                'token_id' => $newToken->accessToken->getKey(),
                'device_name' => $data->deviceName,
                'ip_address' => $data->ipAddress,
                'user_agent' => $this->truncate($data->userAgent),
                'started_at' => now(),
                'last_seen_at' => now(),
            ]);

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $data->ipAddress,
            ])->save();

            $this->recordAttempt($data, $user, successful: true);

            return new AuthenticationResult(
                user: $user,
                token: $newToken->plainTextToken,
                expiresAt: $expiresAt,
                session: $session,
            );
        });
    }

    /**
     * @phpstan-assert User $user
     *
     * @throws AuthenticationFailed
     */
    private function assertCredentials(?User $user, string $password): void
    {
        // Hash a throwaway value when the account does not exist so the response time
        // of "unknown e-mail" matches "wrong password"; otherwise timing alone reveals
        // which addresses are registered.
        if ($user === null) {
            Hash::make($password);

            throw AuthenticationFailed::invalidCredentials();
        }

        if ($user->password_hash === null || ! Hash::check($password, $user->password_hash)) {
            throw AuthenticationFailed::invalidCredentials();
        }

        if ($user->status === UserStatus::Suspended) {
            throw AuthenticationFailed::accountSuspended();
        }

        if ($user->status === UserStatus::Banned) {
            throw AuthenticationFailed::accountBanned();
        }
    }

    private function recordAttempt(
        LoginData $data,
        ?User $user,
        bool $successful,
        ?string $reason = null,
    ): void {
        LoginAttempt::query()->create([
            'user_id' => $user?->getKey(),
            'identifier' => $data->email,
            'ip_address' => $data->ipAddress,
            'user_agent' => $this->truncate($data->userAgent),
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 512);
    }
}

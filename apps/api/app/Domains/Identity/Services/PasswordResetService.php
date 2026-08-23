<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\PasswordResetToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password reset issuing and redemption.
 *
 * Like verification tokens, only hashes are stored. Redeeming a reset also revokes
 * every API token the account holds: if the password had to be reset because it was
 * compromised, leaving existing sessions alive defeats the point.
 */
final class PasswordResetService
{
    public function __construct(private readonly int $ttlMinutes = 60) {}

    /**
     * Issues a reset link for the address, if an account exists.
     *
     * Returns void regardless. The endpoint must answer identically for known and
     * unknown addresses, otherwise it becomes an account-enumeration oracle.
     */
    public function request(string $email, ?string $ip = null, ?string $userAgent = null): void
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        $plaintext = Str::random(64);

        DB::transaction(function () use ($user, $plaintext, $ip, $userAgent): void {
            PasswordResetToken::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            PasswordResetToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->hash($plaintext),
                'expires_at' => now()->addMinutes($this->ttlMinutes),
                'requested_ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            ]);
        });

        $user->notify(new ResetPasswordNotification($plaintext, $this->ttlMinutes));
    }

    /**
     * Redeems a token and sets the new password.
     *
     * @return User|null the account whose password changed, or null if the token was
     *                   unknown, expired or already used
     */
    public function reset(string $plaintext, string $newPassword): ?User
    {
        return DB::transaction(function () use ($plaintext, $newPassword): ?User {
            /** @var PasswordResetToken|null $token */
            $token = PasswordResetToken::query()
                ->where('token_hash', $this->hash($plaintext))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsable()) {
                return null;
            }

            $user = $token->user;

            if ($user === null) {
                return null;
            }

            $token->consumed_at = now();
            $token->save();

            $user->password_hash = Hash::make($newPassword);
            $user->save();

            // A reset implies the old credential is untrusted; every session goes with it.
            $user->tokens()->delete();

            $user->sessions()
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_reason' => 'password_reset']);

            return $user;
        });
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}

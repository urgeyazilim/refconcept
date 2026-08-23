<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\EmailVerificationToken;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and consumes e-mail verification tokens.
 *
 * The plaintext token is returned to the caller exactly once so it can be mailed;
 * only its SHA-256 hash is persisted. Lookups hash the incoming value and match on
 * the stored hash, so a leaked table yields nothing usable.
 */
final class EmailVerificationService
{
    public function __construct(private readonly int $ttlMinutes = 60 * 24) {}

    /**
     * Issues a fresh token and sends the verification e-mail.
     *
     * Any previously issued token is invalidated: two live links for the same address
     * means a link the user believes they revoked still works.
     */
    public function issue(User $user, ?string $requestIp = null): string
    {
        $plaintext = Str::random(64);

        DB::transaction(function () use ($user, $plaintext, $requestIp): void {
            EmailVerificationToken::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            EmailVerificationToken::query()->create([
                'user_id' => $user->getKey(),
                'email' => (string) $user->email,
                'token_hash' => $this->hash($plaintext),
                'expires_at' => now()->addMinutes($this->ttlMinutes),
                'requested_ip' => $requestIp,
            ]);
        });

        $user->notify(new VerifyEmailNotification($plaintext));

        return $plaintext;
    }

    /**
     * Consumes a token and activates the account.
     *
     * Returns null when the token is unknown, expired or already used — the caller
     * cannot distinguish these, because doing so would confirm which tokens exist.
     */
    public function verify(string $plaintext): ?User
    {
        return DB::transaction(function () use ($plaintext): ?User {
            /** @var EmailVerificationToken|null $token */
            $token = EmailVerificationToken::query()
                ->where('token_hash', $this->hash($plaintext))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsable()) {
                return null;
            }

            $user = $token->user;

            // The address may have been changed after the link was sent; verifying it
            // would then mark the *current* address as proven on the strength of an
            // e-mail delivered to the old one.
            if ($user === null || $user->email !== $token->email) {
                return null;
            }

            $token->consumed_at = now();
            $token->save();

            $user->email_verified_at = now();

            if ($user->status === UserStatus::PendingVerification) {
                $user->status = UserStatus::Active;
            }

            $user->save();

            return $user;
        });
    }

    public function expiresAt(): Carbon
    {
        return now()->addMinutes($this->ttlMinutes);
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}

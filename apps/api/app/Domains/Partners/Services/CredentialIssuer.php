<?php

declare(strict_types=1);

namespace App\Domains\Partners\Services;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Partners\Models\ApiCredential;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues and verifies partner API credentials.
 *
 * The secret is returned exactly once, from {@see issue()}, and never again — it is
 * hashed before the row is written and there is no code path that can recover it.
 * That is deliberate and worth the support cost: a credential a system can hand back
 * on demand is a credential an attacker with read access can also collect.
 *
 * Verification is constant-time by way of the password hasher, and the key id is
 * looked up first so an unknown key does not cost a hash computation — that
 * difference in timing is otherwise enough to enumerate valid key ids.
 */
final class CredentialIssuer
{
    /** Prefixed so a leaked key is recognisable in a log or a public repository. */
    private const KEY_PREFIX = 'rck_';

    private const SECRET_PREFIX = 'rcs_';

    /**
     * Creates a credential and returns the plaintext secret with it.
     *
     * @param  array<int, string>  $scopes
     * @return array{credential: ApiCredential, secret: string}
     */
    public function issue(
        Organization $organization,
        string $name,
        array $scopes,
        User $actor,
        ?int $expiresInDays = null,
        int $rateLimitPerMinute = 120,
    ): array {
        $unknown = array_diff($scopes, ApiCredential::SCOPES);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Bilinmeyen yetki: '.implode(', ', $unknown));
        }

        if ($scopes === []) {
            // A credential with no scopes can do nothing; issuing one would only create
            // a support ticket later.
            throw new InvalidArgumentException('En az bir yetki seçilmelidir.');
        }

        $keyId = self::KEY_PREFIX.Str::lower(Str::random(24));
        $secret = self::SECRET_PREFIX.Str::random(48);

        $credential = ApiCredential::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'key_id' => $keyId,
            'secret_hash' => Hash::make($secret),
            'secret_hint' => substr($secret, -4),
            'scopes' => array_values($scopes),
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
            'created_by' => $actor->getKey(),
        ]);

        return ['credential' => $credential, 'secret' => $secret];
    }

    /**
     * Resolves a key/secret pair to a usable credential, or null.
     *
     * Returns null for every failure — unknown key, wrong secret, revoked, expired —
     * because telling a caller *which* of those it was is telling them whether the key
     * exists.
     */
    public function verify(string $keyId, string $secret): ?ApiCredential
    {
        $credential = ApiCredential::query()->where('key_id', $keyId)->first();

        if ($credential === null) {
            // Still spend the time a real comparison would take, so a valid key id
            // cannot be distinguished from an invalid one by how quickly it fails.
            Hash::check($secret, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

            return null;
        }

        if (! Hash::check($secret, $credential->secret_hash)) {
            return null;
        }

        return $credential->isUsable() ? $credential : null;
    }

    /**
     * Revoking is permanent and requires a reason.
     *
     * A dead credential nobody can explain is a support ticket, and a database
     * constraint refuses the row without one.
     */
    public function revoke(ApiCredential $credential, string $reason): ApiCredential
    {
        if ($credential->isRevoked()) {
            return $credential;
        }

        $credential->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => Str::limit($reason, 290),
        ])->save();

        return $credential;
    }

    /** Records that a credential was used, for the seller's own audit. */
    public function touch(ApiCredential $credential, ?string $ip): void
    {
        $credential->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ])->saveQuietly();
    }
}

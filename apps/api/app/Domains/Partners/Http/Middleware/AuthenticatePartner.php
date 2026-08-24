<?php

declare(strict_types=1);

namespace App\Domains\Partners\Http\Middleware;

use App\Domains\Partners\Models\ApiCredential;
use App\Domains\Partners\Models\ApiRequestLog;
use App\Domains\Partners\Services\CredentialIssuer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a partner integration by key and secret.
 *
 * Separate from Sanctum on purpose. A session token belongs to a person and follows
 * them around; a partner credential belongs to a *system*, carries its own scopes,
 * and has to be revocable without touching anybody's account. Conflating the two
 * means revoking a warehouse integration logs somebody out of the seller portal.
 *
 * The credential is put on the request rather than logged in as a user. Nothing
 * downstream should be able to mistake an ERP for a person.
 *
 * Rate limiting is per credential and configurable per credential, because a
 * nightly full-catalogue sync and a live stock feed have genuinely different needs.
 */
final class AuthenticatePartner
{
    public function __construct(private readonly CredentialIssuer $issuer) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $started = microtime(true);

        [$keyId, $secret] = $this->credentialsFrom($request);

        if ($keyId === null || $secret === null) {
            return $this->deny($request, 401, 'Kimlik bilgisi eksik.', null, $started);
        }

        $credential = $this->issuer->verify($keyId, $secret);

        if ($credential === null) {
            // One message for every failure: telling a caller that the key exists but
            // the secret is wrong is telling them the key exists.
            return $this->deny($request, 401, 'Kimlik doğrulanamadı.', null, $started);
        }

        $limiterKey = 'partner:'.$credential->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, $credential->rate_limit_per_minute)) {
            return $this->deny(
                $request,
                429,
                'İstek sınırı aşıldı.',
                $credential,
                $started,
                ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)],
            );
        }

        RateLimiter::hit($limiterKey, 60);

        if ($scope !== null && ! $credential->allows($scope)) {
            return $this->deny(
                $request,
                403,
                sprintf('Bu kimlik bilgisi "%s" yetkisine sahip değil.', $scope),
                $credential,
                $started,
            );
        }

        $request->attributes->set('partner_credential', $credential);
        $request->attributes->set('partner_organization_id', $credential->organization_id);

        $this->issuer->touch($credential, $request->ip());

        $response = $next($request);

        $this->log($request, $response->getStatusCode(), $credential, $started);

        return $response;
    }

    /**
     * Reads the credential from the Authorization header, or from headers of its own.
     *
     * Both forms are accepted because both are what integrators' HTTP clients make
     * easy, and refusing one of them buys nothing.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function credentialsFrom(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');

        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);

            if ($decoded !== false && str_contains($decoded, ':')) {
                [$keyId, $secret] = explode(':', $decoded, 2);

                return [$keyId, $secret];
            }
        }

        $keyId = $request->header('X-RefConcept-Key');
        $secret = $request->header('X-RefConcept-Secret');

        return [
            is_string($keyId) ? $keyId : null,
            is_string($secret) ? $secret : null,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function deny(
        Request $request,
        int $status,
        string $message,
        ?ApiCredential $credential,
        float $started,
        array $headers = [],
    ): Response {
        $this->log($request, $status, $credential, $started);

        return response()->json(['message' => $message], $status, $headers);
    }

    private function log(Request $request, int $status, ?ApiCredential $credential, float $started): void
    {
        ApiRequestLog::query()->create([
            'credential_id' => $credential?->getKey(),
            'method' => $request->method(),
            // The path only: a query string can carry a SKU list or a search term, and
            // this table is read by support staff rather than by the seller.
            'path' => substr($request->path(), 0, 300),
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}

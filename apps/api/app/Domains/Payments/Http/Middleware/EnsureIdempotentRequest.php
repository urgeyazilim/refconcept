<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Middleware;

use App\Domains\Payments\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a POST safe to send twice.
 *
 * A browser on a bad connection retries. A mobile app retries on timeout. A customer with
 * a spinning button presses it again. All three are the *same* request and must produce
 * the same result — not a second payment — so a client may send an `Idempotency-Key` and
 * get its first answer back, byte for byte, however many times it asks.
 *
 * Three cases, and each one matters:
 *
 *  - **First time**: the key is claimed with an INSERT. If the insert loses a race, the
 *    winner is in flight, and we say 409 rather than running the request twice.
 *  - **Same key, same body**: the stored answer is replayed. Not re-executed — replayed.
 *  - **Same key, different body**: refused. That is not a retry, it is a mistake or an
 *    attack, and answering it with somebody else's stored result would be worse than
 *    either.
 *
 * The key is scoped to the route and the user, so two customers cannot collide by both
 * sending "1" and one of them cannot read the other's answer by guessing a key.
 *
 * A missing header is allowed through. Requiring one would break every existing client
 * for a guarantee they did not ask for; the endpoints where a duplicate would be
 * expensive defend themselves anyway — a live payment intent, a ledger reference, a
 * unique index — and this middleware is the convenience on top, not the safety net.
 */
final class EnsureIdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return $next($request);
        }

        if (mb_strlen($key) > 191) {
            return new JsonResponse([
                'message' => 'Idempotency-Key en fazla 191 karakter olabilir.',
            ], 422);
        }

        $scope = $request->method().' '.$request->path();
        $fingerprint = hash('sha256', $request->getContent());
        $userId = $request->user()?->getKey();

        $existing = IdempotencyKey::query()
            ->where('scope', $scope)
            ->where('key', $key)
            ->where('user_id', $userId)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $fingerprint);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'user_id' => $userId,
                'scope' => $scope,
                'key' => $key,
                'request_fingerprint' => $fingerprint,
                'locked_at' => now(),
                'expires_at' => now()->addSeconds((int) config('payments.timings.idempotency_ttl_seconds', 86400)),
            ]);
        } catch (QueryException) {
            /*
             * Somebody claimed it between the select and the insert — two taps, two
             * tabs, or a client retrying inside its own timeout. The unique index settled
             * it; whichever request lost waits rather than running a second time.
             */
            $existing = IdempotencyKey::query()
                ->where('scope', $scope)
                ->where('key', $key)
                ->where('user_id', $userId)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $fingerprint);
            }

            return $this->inFlight();
        }

        $response = $next($request);

        $this->remember($record, $response);

        return $response;
    }

    /**
     * The answer this key has already earned.
     *
     * Three outcomes, and never "carry on": once a key exists, running the request a
     * second time is the one thing that must not happen. The same body gets the stored
     * answer, a different body is refused, and a request still in flight is told to wait.
     */
    private function replay(IdempotencyKey $record, string $fingerprint): Response
    {
        if (! hash_equals($record->request_fingerprint, $fingerprint)) {
            return new JsonResponse([
                'message' => 'Bu Idempotency-Key farklı bir istek için kullanılmış.',
            ], 422);
        }

        if (! $record->isComplete()) {
            return $this->inFlight();
        }

        return new JsonResponse(
            $record->response_body ?? [],
            $record->response_status ?? 200,
            ['Idempotent-Replay' => 'true'],
        );
    }

    private function inFlight(): Response
    {
        // 409 rather than 425 or 429: the request is not too early and not too frequent,
        // it conflicts with one already running. Clients are told to wait and ask again.
        return new JsonResponse([
            'message' => 'Aynı istek hâlâ işleniyor. Lütfen sonucu bekleyin.',
        ], 409, ['Retry-After' => '2']);
    }

    /**
     * Keeps the answer for the next identical request.
     *
     * Only successful answers are kept. A 500 or a 422 stored and replayed would freeze a
     * transient failure into a permanent one for that key — the client retries, gets the
     * same error forever, and no amount of fixing the server helps.
     */
    private function remember(IdempotencyKey $record, Response $response): void
    {
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $record->delete();

            return;
        }

        $body = $response instanceof JsonResponse
            ? $response->getData(true)
            : json_decode((string) $response->getContent(), true);

        $record->forceFill([
            'response_status' => $status,
            'response_body' => is_array($body) ? $body : null,
            'completed_at' => now(),
        ])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, and makes sure everything it touches says so.
 *
 * The audit log has carried a `request_id` column since Phase 1 and nothing was filling
 * it: the logger read `X-Request-Id` from the incoming request, and no client sends one.
 * So the column was reliably null — a field that looks like correlation and is not, which
 * is worse than an absent one because somebody eventually trusts it.
 *
 * The id is taken from the caller when they send one — a load balancer or an upstream
 * service that already assigned one — and generated when they do not. It goes back on the
 * response, so a customer reporting a problem can quote a number that finds the exact
 * request, and into the log context, so every line written while handling it is joined up.
 *
 * A UUIDv7 rather than a random string: it sorts by time, which means a log search for a
 * range of ids is a range scan rather than a guess.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        /*
         * A caller's id is honoured only if it looks like one.
         *
         * It ends up in logs and in the audit trail, both of which are read by people and
         * sometimes rendered — so an unbounded header from outside is an injection waiting
         * for a careless viewer. Anything else is replaced rather than rejected: the
         * request itself is fine and refusing it would be a strange thing to do about a
         * header the caller need not have sent at all.
         */
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        $request->headers->set(self::HEADER, $id);

        // Every log line written while handling this request carries the id, without any
        // of them having to remember to.
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers a response must carry, set by the application rather than by the proxy.
 *
 * nginx sets these too, and that is not enough. A header added by infrastructure is a
 * header that disappears the day somebody puts a different load balancer in front, or
 * routes an internal call straight to PHP-FPM, or runs the API behind a platform that
 * strips unknown headers — and nothing fails, which is the whole problem. Setting them
 * here means the guarantee travels with the application.
 *
 * Deliberately not a Content-Security-Policy. This is a JSON API; the only HTML it serves
 * is the fake payment challenge page, and a policy written for that would be either
 * meaningless here or wrong there. The storefront's CSP belongs with the storefront.
 */
final class SecurityHeaders
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        // No content sniffing: a JSON response reinterpreted as HTML is an XSS vector
        // handed over by the browser rather than by the application.
        'X-Content-Type-Options' => 'nosniff',

        // Never framed. An API reachable from a frame is an API that can be used against
        // whoever is signed into it.
        'X-Frame-Options' => 'DENY',

        // The origin, never the path. A referrer carrying `/account/orders/RC-2026-000123`
        // hands an order number to every third party the page happens to reach.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // Nothing here needs a camera, a microphone or a location.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            // Not overwritten: a controller that has deliberately set its own value knows
            // something this middleware does not.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        /*
         * HSTS only over HTTPS.
         *
         * Sent on a plaintext response it is ignored by browsers and misleading to anybody
         * reading the headers; sent over TLS it is the one header that stops a downgrade
         * before it happens.
         */
        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates actions that require a proven e-mail address.
 *
 * Applied per route rather than globally: an unverified account still needs to read
 * its own profile and request a new verification e-mail, but must not be able to
 * spend credits, place orders or onboard as a seller.
 */
final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isVerified()) {
            return response()->json([
                'message' => 'Bu işlem için e-posta adresinizi doğrulamanız gerekiyor.',
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}

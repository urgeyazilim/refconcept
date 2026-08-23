<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests from accounts that were blocked after their token was issued.
 *
 * Without this, suspending an account would only take effect once every existing
 * token expired — up to 30 days of continued access for someone just banned.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->status->canAuthenticate()) {
            // Revoke on the spot: a blocked account should not keep a working token.
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Hesabınız erişime kapalıdır.',
                'status' => $user->status->value,
            ], 403);
        }

        return $next($request);
    }
}

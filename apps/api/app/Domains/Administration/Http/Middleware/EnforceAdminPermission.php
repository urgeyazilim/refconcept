<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Middleware;

use App\Domains\Administration\Services\AdminPermissionMatrix;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the permission matrix to every administrative request.
 *
 * Middleware rather than a call at the top of each method, and the difference is what
 * happens when somebody forgets. A per-controller check is invisible when it is missing;
 * this one refuses anything under `/admin` that the matrix does not claim, so a new
 * endpoint added without a decision is closed rather than open.
 *
 * Failing closed is the whole design. An unknown admin route is a 403 — not a 404 and not
 * a pass — because "we have not decided who may do this yet" is much closer to "nobody"
 * than to "everybody".
 */
final class EnforceAdminPermission
{
    public function __construct(
        private readonly AdminPermissionMatrix $matrix,
        private readonly AccessControl $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Registered on the whole API group and self-selecting, rather than attached to
         * the admin routes by hand. Attaching it is something somebody can forget on the
         * one route where it mattered; recognising its own territory is not.
         */
        if (! $request->is('api/v1/admin/*')) {
            return $next($request);
        }

        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $permission = $this->matrix->permissionFor($request->route()?->getName());

        // Unclaimed: refused. The test suite fails on the same condition, so this branch
        // should never be reached in a release — it is the safety net under that test.
        abort_if($permission === null, 403, 'Bu işlem için yetki tanımı yapılmamış.');

        abort_unless($this->access->hasPermission($user, $permission), 403);

        return $next($request);
    }
}

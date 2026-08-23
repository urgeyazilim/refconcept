<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\RoleScope;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\OrganizationUser;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do this, here?".
 *
 * Two questions are deliberately kept separate:
 *
 *   - **membership** — is the user part of this organization at all?
 *   - **permission** — do their role grants include this capability?
 *
 * Both must be satisfied for an organization-scoped action. Keeping them apart is
 * what makes cross-tenant leakage a policy bug that a test can catch, rather than a
 * missing `where seller_id = ?` somewhere in a query.
 *
 * Results are memoised per request: an endpoint may check the same permission many
 * times while building a response, and each check would otherwise be a query.
 */
final class AccessControl
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function hasPermission(User $user, Permission|string $permission, ?string $organizationId = null): bool
    {
        $name = $permission instanceof Permission ? $permission->value : $permission;
        $cacheKey = $user->getKey().'|'.$name.'|'.($organizationId ?? '-');

        return $this->cache[$cacheKey] ??= $this->resolvePermission($user, $name, $organizationId);
    }

    /**
     * Super admin bypasses the permission table entirely. This is registered as a
     * Gate::before hook as well; having it here keeps direct service calls consistent
     * with policy checks.
     */
    public function isSuperAdmin(User $user): bool
    {
        $cacheKey = $user->getKey().'|__super_admin__';

        return $this->cache[$cacheKey] ??= DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->getKey())
            ->where('roles.slug', SystemRole::SuperAdmin->value)
            ->where(function ($query): void {
                $query->whereNull('user_roles.expires_at')
                    ->orWhere('user_roles.expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Membership is the tenant gate. An invited-but-not-joined or removed member is
     * not a member: only `active` counts.
     */
    public function belongsToOrganization(User $user, string $organizationId): bool
    {
        $cacheKey = $user->getKey().'|__member__|'.$organizationId;

        return $this->cache[$cacheKey] ??= OrganizationUser::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    /**
     * @return array<int, string> organization ids the user is an active member of
     */
    public function organizationIds(User $user): array
    {
        return OrganizationUser::query()
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->pluck('organization_id')
            ->all();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function resolvePermission(User $user, string $permission, ?string $organizationId): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // An organization-scoped question is meaningless if the user is not a member,
        // and answering it from platform roles alone would leak across tenants.
        if ($organizationId !== null && ! $this->belongsToOrganization($user, $organizationId)) {
            return false;
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->getKey())
            ->where('permissions.name', $permission)
            ->where(function ($query): void {
                $query->whereNull('user_roles.expires_at')
                    ->orWhere('user_roles.expires_at', '>', now());
            })
            ->where(function ($query) use ($organizationId): void {
                // Platform grants apply everywhere.
                $query->where('roles.scope', RoleScope::Platform->value);

                // Organization grants apply only inside the organization asked about.
                if ($organizationId !== null) {
                    $query->orWhere(function ($inner) use ($organizationId): void {
                        $inner->where('roles.scope', RoleScope::Organization->value)
                            ->where('user_roles.organization_id', $organizationId);
                    });
                }
            })
            ->exists();
    }
}

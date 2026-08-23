<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Organizations\Models\Organization;

/**
 * The tenant isolation boundary.
 *
 * Every organization-scoped check asks the same two questions in the same order:
 * is this user a member of *this* organization, and does their grant inside it carry
 * the permission? Platform staff pass through their platform-scoped permissions.
 *
 * This is where "seller A cannot read seller B" is decided — which is why the tenant
 * isolation test suite targets this class directly as well as through endpoints.
 */
final class OrganizationPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsView);
    }

    public function view(User $user, Organization $organization): bool
    {
        // Platform-level visibility (support, operations) …
        if ($this->access->hasPermission($user, Permission::OrganizationsView)) {
            return true;
        }

        // … or membership of this specific organization.
        return $this->access->belongsToOrganization($user, (string) $organization->getKey());
    }

    public function update(User $user, Organization $organization): bool
    {
        if ($this->access->hasPermission($user, Permission::OrganizationsManage)) {
            return true;
        }

        return $this->access->hasPermission(
            $user,
            Permission::SellerProfileManage,
            (string) $organization->getKey(),
        );
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        if ($this->access->hasPermission($user, Permission::OrganizationsManage)) {
            return true;
        }

        return $this->access->hasPermission(
            $user,
            Permission::SellerUsersManage,
            (string) $organization->getKey(),
        );
    }

    /**
     * Creating and suspending organizations is a platform action. A seller cannot
     * spawn a second organization to escape a suspension on their first.
     */
    public function create(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }
}

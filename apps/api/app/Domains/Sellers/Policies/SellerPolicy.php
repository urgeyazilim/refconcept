<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Policies;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Sellers\Models\Seller;

/**
 * Access to an approved seller.
 *
 * Reading is either a platform permission or membership of that seller's own
 * organization — the same two questions the tenant boundary always asks. Suspension
 * and reactivation are platform-only: a seller must never be able to lift their own
 * suspension.
 */
final class SellerPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsView);
    }

    public function view(User $user, Seller $seller): bool
    {
        if ($this->access->hasPermission($user, Permission::OrganizationsView)) {
            return true;
        }

        return $this->access->belongsToOrganization($user, $seller->organization_id);
    }

    public function update(User $user, Seller $seller): bool
    {
        if ($this->access->hasPermission($user, Permission::OrganizationsManage)) {
            return true;
        }

        return $this->access->hasPermission(
            $user,
            Permission::SellerProfileManage,
            $seller->organization_id,
        );
    }

    public function suspend(User $user, Seller $seller): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    public function reactivate(User $user, Seller $seller): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }

    /** Commission is platform economics; a seller cannot set their own rate. */
    public function setCommission(User $user, Seller $seller): bool
    {
        return $this->access->hasPermission($user, Permission::OrganizationsManage);
    }
}

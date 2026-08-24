<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Sellers\Models\Seller;

/*
|--------------------------------------------------------------------------
| Shared test helpers
|--------------------------------------------------------------------------
| Pest test files share one global function namespace, so a helper defined in two
| suites is a fatal redeclare. These live here, loaded once through composer's
| autoload-dev files, and are available to every suite.
|
| They exist because setting up a seller is genuinely involved — organization,
| membership, role grant and seller row — and repeating it inline buries the
| behaviour each test is actually about.
*/

if (! function_exists('grantPlatformRole')) {
    /**
     * Grants a platform-scoped role.
     *
     * There is no HTTP endpoint for this on purpose, so tests take the same route the
     * console command does.
     */
    function grantPlatformRole(User $user, SystemRole $role): UserRole
    {
        $model = Role::query()
            ->where('slug', $role->value)
            ->where('scope', $role->scope()->value)
            ->firstOrFail();

        return UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $model->getKey(),
            'organization_id' => null,
            'granted_at' => now(),
        ]);
    }
}

if (! function_exists('makeApprovedSeller')) {
    /**
     * Creates an approved seller with an owner who can act inside it.
     *
     * Membership and a role grant are both created, because either alone is useless:
     * membership without a grant authorises nothing, a grant without membership never
     * matches.
     *
     * @return array{0: Seller, 1: User}
     */
    function makeApprovedSeller(string $name, string $slug, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();

        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => OrganizationType::Seller,
            'status' => OrganizationStatus::Active,
            'owner_user_id' => $owner->getKey(),
        ]);

        OrganizationUser::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);

        $role = Role::query()
            ->where('slug', SystemRole::SellerOwner->value)
            ->where('scope', SystemRole::SellerOwner->scope()->value)
            ->firstOrFail();

        UserRole::query()->create([
            'user_id' => $owner->getKey(),
            'role_id' => $role->getKey(),
            'organization_id' => $organization->getKey(),
            'granted_at' => now(),
        ]);

        $seller = Seller::query()->create([
            'organization_id' => $organization->getKey(),
            'seller_code' => 'RC-'.strtoupper(substr(md5($slug.microtime()), 0, 6)),
            'display_name' => $name,
        ]);

        return [$seller, $owner];
    }
}

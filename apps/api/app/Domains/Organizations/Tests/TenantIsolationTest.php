<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The rule this file exists to defend:
 *
 *   Seller A must never, under any circumstance, reach Seller B's data.
 *
 * Every phase from here on adds seller-owned resources, so these cases are the
 * regression net for the isolation boundary itself rather than for any one endpoint.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->sellerA = makeSellerOrganization('Atlas Mobilya', 'atlas');
    $this->sellerB = makeSellerOrganization('Nova Yaşam', 'nova');

    $this->access = app(AccessControl::class);
});

it('grants a seller owner permissions inside their own organization', function (): void {
    expect($this->access->hasPermission(
        $this->sellerA['owner'],
        Permission::SellerProfileManage,
        (string) $this->sellerA['organization']->getKey(),
    ))->toBeTrue();
});

it('denies the same permission in another organization', function (): void {
    expect($this->access->hasPermission(
        $this->sellerA['owner'],
        Permission::SellerProfileManage,
        (string) $this->sellerB['organization']->getKey(),
    ))->toBeFalse();
});

it('denies an organization-scoped permission when asked without a scope', function (): void {
    // An organization grant must never leak into a platform-wide answer.
    expect($this->access->hasPermission(
        $this->sellerA['owner'],
        Permission::SellerProfileManage,
    ))->toBeFalse();
});

it('reports membership only for the organization the user belongs to', function (): void {
    expect($this->access->belongsToOrganization($this->sellerA['owner'], (string) $this->sellerA['organization']->getKey()))->toBeTrue()
        ->and($this->access->belongsToOrganization($this->sellerA['owner'], (string) $this->sellerB['organization']->getKey()))->toBeFalse();
});

it('treats a removed member as not a member', function (): void {
    OrganizationUser::query()
        ->where('organization_id', $this->sellerA['organization']->getKey())
        ->where('user_id', $this->sellerA['owner']->getKey())
        ->update(['status' => MembershipStatus::Removed->value, 'removed_at' => now()]);

    $this->access->flush();

    expect($this->access->belongsToOrganization($this->sellerA['owner'], (string) $this->sellerA['organization']->getKey()))->toBeFalse()
        ->and($this->access->hasPermission(
            $this->sellerA['owner'],
            Permission::SellerProfileManage,
            (string) $this->sellerA['organization']->getKey(),
        ))->toBeFalse();
});

it('treats an invited but not yet joined member as not a member', function (): void {
    OrganizationUser::query()
        ->where('organization_id', $this->sellerA['organization']->getKey())
        ->where('user_id', $this->sellerA['owner']->getKey())
        ->update(['status' => MembershipStatus::Invited->value, 'joined_at' => null]);

    $this->access->flush();

    expect($this->access->belongsToOrganization($this->sellerA['owner'], (string) $this->sellerA['organization']->getKey()))->toBeFalse();
});

it('lets a seller owner view their own organization but not another', function (): void {
    expect($this->sellerA['owner']->can('view', $this->sellerA['organization']))->toBeTrue()
        ->and($this->sellerA['owner']->can('view', $this->sellerB['organization']))->toBeFalse();
});

it('lets a seller owner update their own organization but not another', function (): void {
    expect($this->sellerA['owner']->can('update', $this->sellerA['organization']))->toBeTrue()
        ->and($this->sellerA['owner']->can('update', $this->sellerB['organization']))->toBeFalse();
});

it('does not let a seller owner suspend any organization, including their own', function (): void {
    // Otherwise a seller could manipulate their own suspension state.
    expect($this->sellerA['owner']->can('suspend', $this->sellerA['organization']))->toBeFalse()
        ->and($this->sellerA['owner']->can('suspend', $this->sellerB['organization']))->toBeFalse();
});

it('does not let a seller owner create organizations', function (): void {
    // A suspended seller must not be able to spawn a fresh organization to escape it.
    expect($this->sellerA['owner']->can('create', Organization::class))->toBeFalse();
});

it('gives a plain customer no organization access at all', function (): void {
    $customer = User::factory()->create();

    expect($customer->can('view', $this->sellerA['organization']))->toBeFalse()
        ->and($this->access->organizationIds($customer))->toBe([]);
});

it('lets a super admin reach every organization', function (): void {
    $admin = User::factory()->create();
    grantPlatformRole($admin, SystemRole::SuperAdmin);

    expect($admin->can('view', $this->sellerA['organization']))->toBeTrue()
        ->and($admin->can('view', $this->sellerB['organization']))->toBeTrue()
        ->and($admin->can('suspend', $this->sellerB['organization']))->toBeTrue();
});

it('lets an operator view organizations but keeps analysts read-only', function (): void {
    $operator = User::factory()->create();
    grantPlatformRole($operator, SystemRole::Operator);

    $analyst = User::factory()->create();
    grantPlatformRole($analyst, SystemRole::Analyst);

    expect($operator->can('view', $this->sellerA['organization']))->toBeTrue()
        ->and($operator->can('update', $this->sellerA['organization']))->toBeTrue()
        ->and($analyst->can('view', $this->sellerA['organization']))->toBeTrue()
        ->and($analyst->can('update', $this->sellerA['organization']))->toBeFalse();
});

it('stops honouring a grant once it expires', function (): void {
    UserRole::query()
        ->where('user_id', $this->sellerA['owner']->getKey())
        ->update(['expires_at' => now()->subMinute()]);

    $this->access->flush();

    expect($this->access->hasPermission(
        $this->sellerA['owner'],
        Permission::SellerProfileManage,
        (string) $this->sellerA['organization']->getKey(),
    ))->toBeFalse();
});

it('keeps one customer out of another customer address book', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $address = UserAddress::query()->create([
        'user_id' => $owner->getKey(),
        'recipient_name' => 'Ev Sahibi',
        'city' => 'İstanbul',
        'address_line1' => 'Bir sokak 1',
    ]);

    expect($owner->can('view', $address))->toBeTrue()
        ->and($stranger->can('view', $address))->toBeFalse()
        ->and($stranger->can('update', $address))->toBeFalse()
        ->and($stranger->can('delete', $address))->toBeFalse();

    $this->actingAs($stranger)
        ->getJson("/api/v1/addresses/{$address->getKey()}")
        ->assertForbidden();
});

/**
 * @return array{organization: Organization, owner: User}
 */
function makeSellerOrganization(string $name, string $slug): array
{
    $owner = User::factory()->create();

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

    return ['organization' => $organization, 'owner' => $owner];
}

function grantPlatformRole(User $user, SystemRole $role): void
{
    $roleModel = Role::query()
        ->where('slug', $role->value)
        ->where('scope', $role->scope()->value)
        ->firstOrFail();

    UserRole::query()->create([
        'user_id' => $user->getKey(),
        'role_id' => $roleModel->getKey(),
        'organization_id' => null,
        'granted_at' => now(),
    ]);
}

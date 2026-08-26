<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Sellers\Services\SellerTeam;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * A seller is a company, not a person.
 *
 * Somebody dispatches parcels, somebody else answers returns, and the person whose name is
 * on the bank account does neither. If the platform does not model that, the company solves
 * it by sharing one login — and then every audit entry says "the seller" and means nobody.
 *
 * Two questions run through these tests. **Can the right people add somebody?** and,
 * much more importantly, **can they lock themselves out?** The second is the one that turns
 * into a support ticket and a console command, so the last owner cannot demote or remove
 * themselves however the request is shaped.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->seller, $this->owner] = makeApprovedSeller('Ekip Mobilya', 'ekip-mobilya');

    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $this->organization = $this->seller->organization;

    $this->team = app(SellerTeam::class);

    // Somebody with an account who does not work anywhere yet.
    $this->colleague = User::factory()->create(['email' => 'depo@ekip-mobilya.test']);
    $this->colleague->forceFill(['email_verified_at' => now()])->save();
});

/** Adds the colleague through the API, the way an owner would. */
function addColleague(string $role = 'seller-staff'): void
{
    test()->actingAs(test()->owner)
        ->postJson('/api/v1/seller/team', ['email' => test()->colleague->email, 'role' => $role])
        ->assertCreated();
}

// --- the ordinary case ------------------------------------------------------------

it('lets an owner add a colleague who already has an account', function (): void {
    addColleague();

    $membership = OrganizationUser::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->colleague->getKey())
        ->firstOrFail();

    expect($membership->status)->toBe(MembershipStatus::Active)
        ->and($this->team->roleOf($this->organization, $this->colleague))
        ->toBe(SystemRole::SellerStaff);
});

it('writes the membership and the role together', function (): void {
    addColleague();

    /*
     * Neither half means anything alone. A membership with no role is somebody who can
     * sign in and see nothing; a role with no membership is a permission pointing at a
     * company the person does not belong to.
     */
    expect($this->team->roleOf($this->organization, $this->colleague))->not->toBeNull();

    $listed = collect($this->actingAs($this->owner)->getJson('/api/v1/seller/team')->assertOk()->json('data'))
        ->firstWhere('user_id', $this->colleague->getKey());

    expect($listed['role'])->toBe('seller-staff')
        ->and($listed['status'])->toBe('active');
});

it('refuses an address with no account behind it', function (): void {
    // Creating one here would let a seller set a password for an address they do not
    // control, and "somebody added me to their company" is not a reason to hand over an
    // account.
    $this->actingAs($this->owner)
        ->postJson('/api/v1/seller/team', ['email' => 'kimse@yok.test', 'role' => 'seller-staff'])
        ->assertStatus(422);
});

it('refuses to add the same person twice', function (): void {
    addColleague();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/seller/team', ['email' => $this->colleague->email, 'role' => 'seller-staff'])
        ->assertStatus(409);
});

it('refuses a role that is not a seller role', function (): void {
    // Especially this one: a seller granting themselves a platform role would be a
    // privilege-escalation endpoint wearing a team screen.
    $this->actingAs($this->owner)
        ->postJson('/api/v1/seller/team', ['email' => $this->colleague->email, 'role' => 'super-admin'])
        ->assertStatus(422);

    expect($this->team->roleOf($this->organization, $this->colleague))->toBeNull();
});

// --- the refusals that save somebody their account --------------------------------

it('never lets the last owner demote themselves', function (): void {
    $membership = OrganizationUser::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    /*
     * A company with no owner is a company where nobody can add one back, and the only
     * way out is a support ticket and a console command. Refusing costs a click.
     */
    $this->actingAs($this->owner)
        ->patchJson('/api/v1/seller/team/'.$membership->getKey(), ['role' => 'seller-staff'])
        ->assertStatus(409);

    expect($this->team->roleOf($this->organization, $this->owner))->toBe(SystemRole::SellerOwner);
});

it('never lets the last owner remove themselves', function (): void {
    $membership = OrganizationUser::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/seller/team/'.$membership->getKey())
        ->assertStatus(409);
});

it('lets an owner step back once there is a second one', function (): void {
    addColleague('seller-owner');

    $membership = OrganizationUser::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->owner->getKey())
        ->firstOrFail();

    $this->actingAs($this->owner)
        ->patchJson('/api/v1/seller/team/'.$membership->getKey(), ['role' => 'seller-staff'])
        ->assertOk();

    expect($this->team->roleOf($this->organization, $this->owner))->toBe(SystemRole::SellerStaff)
        ->and($this->team->roleOf($this->organization, $this->colleague))->toBe(SystemRole::SellerOwner);
});

// --- removal ----------------------------------------------------------------------

it('keeps a removed member on the record rather than deleting them', function (): void {
    addColleague();

    $membership = OrganizationUser::query()
        ->where('user_id', $this->colleague->getKey())
        ->firstOrFail();

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/seller/team/'.$membership->getKey())
        ->assertOk();

    /*
     * The orders they confirmed and the returns they decided still name them. An audit
     * trail pointing at a row that no longer exists has lost the answer it was kept for.
     */
    $fresh = $membership->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBe(MembershipStatus::Removed)
        ->and($fresh?->removed_at)->not->toBeNull()
        ->and($this->team->roleOf($this->organization, $this->colleague))->toBeNull();
});

it('stops a removed member from reaching the seller portal', function (): void {
    addColleague();

    $membership = OrganizationUser::query()->where('user_id', $this->colleague->getKey())->firstOrFail();

    $this->actingAs($this->owner)->deleteJson('/api/v1/seller/team/'.$membership->getKey())->assertOk();

    // The role went with the membership, so the permission check has nothing to find.
    $this->actingAs($this->colleague)->getJson('/api/v1/seller/team')->assertNotFound();
});

it('lets a removed member be added back', function (): void {
    addColleague();

    $membership = OrganizationUser::query()->where('user_id', $this->colleague->getKey())->firstOrFail();

    $this->actingAs($this->owner)->deleteJson('/api/v1/seller/team/'.$membership->getKey())->assertOk();

    // Somebody coming back from parental leave is not a new company.
    addColleague();

    expect($membership->fresh()?->status)->toBe(MembershipStatus::Active)
        ->and($this->team->roleOf($this->organization, $this->colleague))->toBe(SystemRole::SellerStaff);
});

// --- who may do what --------------------------------------------------------------

it('lets staff see their colleagues and change nothing', function (): void {
    addColleague();

    // Seeing who they work with: a returns queue where "kim onayladı" shows an unfamiliar
    // name is worse than no name at all.
    $response = $this->actingAs($this->colleague)->getJson('/api/v1/seller/team')->assertOk();

    expect($response->json('meta.can_manage'))->toBeFalse()
        ->and($response->json('meta.your_role'))->toBe('seller-staff');

    $ownerMembership = OrganizationUser::query()->where('user_id', $this->owner->getKey())->firstOrFail();

    $this->actingAs($this->colleague)
        ->patchJson('/api/v1/seller/team/'.$ownerMembership->getKey(), ['role' => 'seller-staff'])
        ->assertForbidden();

    $this->actingAs($this->colleague)
        ->postJson('/api/v1/seller/team', ['email' => 'baska@ekip.test', 'role' => 'seller-staff'])
        ->assertForbidden();
});

it('keeps one seller out of another seller team', function (): void {
    [, $rival] = makeApprovedSeller('Rakip Mobilya', 'rakip-mobilya');
    $rival->forceFill(['email_verified_at' => now()])->save();

    $membership = OrganizationUser::query()->where('user_id', $this->owner->getKey())->firstOrFail();

    /*
     * A 404, not a 403. Whether another seller has a member of that id is not something
     * to confirm — the organization is resolved from the caller, so the id in the path
     * simply does not belong to anything they can see.
     */
    $this->actingAs($rival)
        ->patchJson('/api/v1/seller/team/'.$membership->getKey(), ['role' => 'seller-staff'])
        ->assertNotFound();

    $this->actingAs($rival)
        ->deleteJson('/api/v1/seller/team/'.$membership->getKey())
        ->assertNotFound();
});

it('refuses somebody who already works for another seller', function (): void {
    [, $rivalOwner] = makeApprovedSeller('Baska Mobilya', 'baska-mobilya');

    /*
     * One person, one seller. Somebody on two teams would see two companies' orders
     * through one session, and every isolation guarantee in this platform is written per
     * organization.
     */
    $this->actingAs($this->owner)
        ->postJson('/api/v1/seller/team', ['email' => $rivalOwner->email, 'role' => 'seller-staff'])
        ->assertStatus(409);
});

it('keeps a customer out of the team endpoints entirely', function (): void {
    $customer = User::factory()->create();
    $customer->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($customer)->getJson('/api/v1/seller/team')->assertNotFound();
    $this->actingAs($customer)
        ->postJson('/api/v1/seller/team', ['email' => $this->colleague->email, 'role' => 'seller-staff'])
        ->assertNotFound();
});

// --- the record -------------------------------------------------------------------

it('audits every change to the team', function (): void {
    addColleague();

    $membership = OrganizationUser::query()->where('user_id', $this->colleague->getKey())->firstOrFail();

    $this->actingAs($this->owner)
        ->patchJson('/api/v1/seller/team/'.$membership->getKey(), ['role' => 'seller-owner'])
        ->assertOk();

    $actions = AuditLog::query()
        ->where('action', 'like', 'seller.team.%')
        ->pluck('action')
        ->all();

    // Who let somebody into the company, and who changed what they may do, are both
    // questions somebody will one day have to answer.
    expect($actions)->toContain('seller.team.member_added')
        ->and($actions)->toContain('seller.team.role_changed');
});

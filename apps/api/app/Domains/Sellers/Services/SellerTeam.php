<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Sellers\Exceptions\TeamRefused;
use Illuminate\Support\Facades\DB;

/**
 * Who works for a seller, and what they may do.
 *
 * A seller is a company rather than a person: somebody dispatches parcels, somebody else
 * answers returns, and the person whose name is on the bank account does neither. Sharing
 * one login is how that gets solved when the platform does not solve it — and then every
 * audit entry says "the seller" and means nobody.
 *
 * Two roles, deliberately. **Owner** can do everything including changing the team and the
 * payout account; **staff** can work the day-to-day and cannot. A third rung would need a
 * permission editor, and a permission editor a seller can use is a way to lock themselves
 * out of their own account.
 *
 * Membership and role are written together. A membership without a role is somebody who
 * can sign in and see nothing; a role without a membership is a permission pointing at a
 * company the person does not belong to. Neither state has a meaning, so neither is
 * reachable — one transaction, both rows.
 */
final class SellerTeam
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The team as it stands, invited members included.
     *
     * @return list<array<string, mixed>>
     */
    public function members(Organization $organization): array
    {
        $roles = UserRole::query()
            ->where('organization_id', $organization->getKey())
            ->with('role')
            ->get()
            ->keyBy('user_id');

        return OrganizationUser::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', [MembershipStatus::Invited->value, MembershipStatus::Active->value])
            // The profile as well: displayName() reads it, and lazy loading is disabled —
            // as it should be, because a list of twenty members would otherwise be a list
            // of twenty extra queries.
            ->with('user.profile')
            ->orderBy('created_at')
            ->get()
            ->map(static function (OrganizationUser $membership) use ($roles): array {
                $role = $roles->get($membership->user_id);

                return [
                    'id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'email' => $membership->user?->email,
                    'name' => $membership->user?->displayName(),
                    'status' => $membership->status->value,
                    'role' => $role?->role?->slug,
                    'role_label' => $role?->role?->name,
                    'invited_at' => $membership->invited_at?->toIso8601String(),
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Adds a colleague, or restores one who was removed.
     *
     * The person must already have an account. Creating one from here would mean a seller
     * could set a password for an address they do not control, and "somebody added me to
     * their company" is not a reason to hand over an account.
     *
     * @throws TeamRefused
     */
    public function add(Organization $organization, User $user, SystemRole $role, User $actor): OrganizationUser
    {
        if (! in_array($role, [SystemRole::SellerOwner, SystemRole::SellerStaff], true)) {
            throw TeamRefused::unknownRole($role->value);
        }

        $existing = OrganizationUser::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null && $existing->status === MembershipStatus::Active) {
            throw TeamRefused::alreadyAMember();
        }

        // Somebody who belongs to one seller cannot be added to another. A person on two
        // teams would see two companies' orders through one session, and every isolation
        // guarantee in the platform is written per organization.
        $elsewhere = OrganizationUser::query()
            ->where('user_id', $user->getKey())
            ->where('organization_id', '!=', $organization->getKey())
            ->whereIn('status', [MembershipStatus::Invited->value, MembershipStatus::Active->value])
            ->exists();

        if ($elsewhere) {
            throw TeamRefused::belongsElsewhere();
        }

        return DB::transaction(function () use ($organization, $user, $role, $actor, $existing): OrganizationUser {
            $membership = $existing ?? new OrganizationUser([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
            ]);

            $membership->forceFill([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'status' => MembershipStatus::Active,
                'invited_by' => $actor->getKey(),
                'invited_at' => $membership->invited_at ?? now(),
                'joined_at' => now(),
                'removed_at' => null,
            ])->save();

            $this->setRole($organization, $user, $role, $actor);

            $this->audit->record(
                action: 'seller.team.member_added',
                subject: $membership,
                context: ['role' => $role->value, 'organization_id' => $organization->getKey()],
                actor: $actor,
            );

            return $membership;
        });
    }

    /**
     * Changes what somebody may do.
     *
     * @throws TeamRefused
     */
    public function changeRole(Organization $organization, User $user, SystemRole $role, User $actor): void
    {
        if (! in_array($role, [SystemRole::SellerOwner, SystemRole::SellerStaff], true)) {
            throw TeamRefused::unknownRole($role->value);
        }

        $membership = $this->activeMembership($organization, $user);

        /*
         * The last owner cannot demote themselves.
         *
         * A company with no owner is a company where nobody can add one back, and the
         * only way out is a support ticket and a console command. Refusing here costs a
         * click; not refusing costs somebody their account.
         */
        if ($this->isOnlyOwner($organization, $user) && $role !== SystemRole::SellerOwner) {
            throw TeamRefused::lastOwner();
        }

        $before = $this->roleOf($organization, $user)?->value;

        $this->setRole($organization, $user, $role, $actor);

        $this->audit->record(
            action: 'seller.team.role_changed',
            subject: $membership,
            changes: ['role' => [$before, $role->value]],
            context: ['organization_id' => $organization->getKey()],
            actor: $actor,
        );
    }

    /**
     * Takes somebody off the team.
     *
     * Marked removed rather than deleted: the orders they confirmed and the returns they
     * decided still name them, and an audit trail pointing at a row that no longer exists
     * is an audit trail that has lost the answer.
     *
     * @throws TeamRefused
     */
    public function remove(Organization $organization, User $user, User $actor): void
    {
        $membership = $this->activeMembership($organization, $user);

        if ($this->isOnlyOwner($organization, $user)) {
            throw TeamRefused::lastOwner();
        }

        DB::transaction(function () use ($organization, $user, $actor, $membership): void {
            $membership->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
            ])->save();

            UserRole::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->delete();

            $this->audit->record(
                action: 'seller.team.member_removed',
                subject: $membership,
                context: ['organization_id' => $organization->getKey()],
                actor: $actor,
            );
        });
    }

    public function roleOf(Organization $organization, User $user): ?SystemRole
    {
        $slug = UserRole::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->value('roles.slug');

        return $slug === null ? null : SystemRole::tryFrom((string) $slug);
    }

    // --- internals -----------------------------------------------------------

    /** @throws TeamRefused */
    private function activeMembership(Organization $organization, User $user): OrganizationUser
    {
        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->whereIn('status', [MembershipStatus::Invited->value, MembershipStatus::Active->value])
            ->first();

        if ($membership === null) {
            throw TeamRefused::notAMember();
        }

        return $membership;
    }

    private function isOnlyOwner(Organization $organization, User $user): bool
    {
        if ($this->roleOf($organization, $user) !== SystemRole::SellerOwner) {
            return false;
        }

        $owners = UserRole::query()
            ->where('user_roles.organization_id', $organization->getKey())
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.slug', SystemRole::SellerOwner->value)
            ->count();

        return $owners <= 1;
    }

    /** One organization-scoped role per person: the new one replaces the old. */
    private function setRole(Organization $organization, User $user, SystemRole $role, User $actor): void
    {
        $roleRow = Role::query()
            ->where('slug', $role->value)
            ->where('scope', $role->scope()->value)
            ->firstOrFail();

        UserRole::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->delete();

        UserRole::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $roleRow->getKey(),
            'organization_id' => $organization->getKey(),
            'granted_by' => $actor->getKey(),
            'granted_at' => now(),
        ]);
    }
}

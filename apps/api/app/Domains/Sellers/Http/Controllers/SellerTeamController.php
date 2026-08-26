<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use App\Domains\Sellers\Exceptions\TeamRefused;
use App\Domains\Sellers\Services\SellerTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A seller managing their own colleagues.
 *
 * The organization is always resolved from the caller rather than taken from the path.
 * There is no id to tamper with, so one seller cannot address another's team however the
 * request is shaped — the same reason the application routes have no id either.
 *
 * Reading the team needs `seller.users.view`; changing it needs `seller.users.manage`,
 * which only an owner has. Staff can see who their colleagues are — a returns queue where
 * "kim onayladı" shows an unfamiliar name is worse than no name — and cannot change them.
 */
final class SellerTeamController
{
    public function __construct(
        private readonly SellerTeam $team,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$user, $organization] = $this->context($request);

        $this->authorize($user, $organization, Permission::SellerUsersView);

        return response()->json([
            'data' => $this->team->members($organization),
            'meta' => [
                // What the caller may do, so the page can explain a missing button rather
                // than showing one that answers 403.
                'can_manage' => $this->access->hasPermission(
                    $user,
                    Permission::SellerUsersManage,
                    (string) $organization->getKey(),
                ),
                'your_role' => $this->team->roleOf($organization, $user)?->value,
                'roles' => [
                    [
                        'value' => SystemRole::SellerOwner->value,
                        'label' => SystemRole::SellerOwner->label(),
                        'description' => 'Ekibi ve ödeme hesabını da yönetebilir.',
                    ],
                    [
                        'value' => SystemRole::SellerStaff->value,
                        'label' => SystemRole::SellerStaff->label(),
                        'description' => 'Sipariş, ürün ve iadeleri yönetir; ekibi değiştiremez.',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Adds a colleague who already has an account.
     *
     * @throws TeamRefused
     */
    public function store(Request $request): JsonResponse
    {
        [$user, $organization] = $this->context($request);

        $this->authorize($user, $organization, Permission::SellerUsersManage);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        $role = SystemRole::tryFrom($validated['role']);

        if ($role === null) {
            throw TeamRefused::unknownRole($validated['role']);
        }

        /*
         * The account must exist. Creating one from here would let a seller set a password
         * for an address they do not control, and "somebody added me to their company" is
         * not a reason to hand over an account.
         */
        $member = User::query()->where('email', $validated['email'])->first();

        if (! $member instanceof User) {
            throw TeamRefused::noAccount($validated['email']);
        }

        $membership = $this->team->add($organization, $member, $role, $user);

        return response()->json([
            'message' => 'Ekip üyesi eklendi.',
            'data' => ['id' => $membership->id],
        ], 201);
    }

    /**
     * @throws TeamRefused
     */
    public function update(Request $request, OrganizationUser $member): JsonResponse
    {
        [$user, $organization] = $this->context($request);

        $this->authorize($user, $organization, Permission::SellerUsersManage);

        // Not theirs: a 404, because whether another seller has a member of that id is not
        // something to confirm.
        abort_unless($member->organization_id === $organization->getKey(), 404);

        $validated = $request->validate(['role' => ['required', 'string']]);

        $role = SystemRole::tryFrom($validated['role']);

        if ($role === null) {
            throw TeamRefused::unknownRole($validated['role']);
        }

        $this->team->changeRole($organization, $this->memberUser($member), $role, $user);

        return response()->json(['message' => 'Rol güncellendi.']);
    }

    /**
     * @throws TeamRefused
     */
    public function destroy(Request $request, OrganizationUser $member): JsonResponse
    {
        [$user, $organization] = $this->context($request);

        $this->authorize($user, $organization, Permission::SellerUsersManage);

        abort_unless($member->organization_id === $organization->getKey(), 404);

        $this->team->remove($organization, $this->memberUser($member), $user);

        return response()->json(['message' => 'Ekip üyesi çıkarıldı.']);
    }

    // --- internals -----------------------------------------------------------

    /**
     * The caller and the organization they belong to.
     *
     * @return array{0: User, 1: Organization}
     */
    private function context(Request $request): array
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $organization = Organization::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active->value))
            ->first();

        abort_if($organization === null, 404, 'Bu hesaba bağlı bir satıcı kaydı yok.');

        return [$user, $organization];
    }

    private function authorize(User $user, Organization $organization, Permission $permission): void
    {
        abort_unless(
            $this->access->hasPermission($user, $permission, (string) $organization->getKey()),
            403,
        );
    }

    private function memberUser(OrganizationUser $member): User
    {
        $user = $member->user;

        abort_unless($user instanceof User, 404);

        return $user;
    }
}

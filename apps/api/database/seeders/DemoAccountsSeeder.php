<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserProfile;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\OrganizationUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for local development and staging.
 *
 * Never runs in production (see DatabaseSeeder). Two seller organizations are created
 * on purpose, each with its own owner: tenant isolation cannot be exercised — by a
 * developer or by the test suite — with only one tenant in the database.
 */
final class DemoAccountsSeeder extends Seeder
{
    private const PASSWORD = 'RefConcept2026!';

    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = $this->createUser('admin@refconcept.local', 'Platform', 'Admin');
            $this->grantPlatformRole($admin, SystemRole::SuperAdmin);

            $operator = $this->createUser('operator@refconcept.local', 'Operasyon', 'Kullanıcı');
            $this->grantPlatformRole($operator, SystemRole::Operator);

            $this->createUser('customer@refconcept.local', 'Demo', 'Müşteri');

            $this->createSeller('Atlas Mobilya', 'atlas-mobilya', 'seller-a@refconcept.local', 'Atlas', 'Sahibi');
            $this->createSeller('Nova Yaşam', 'nova-yasam', 'seller-b@refconcept.local', 'Nova', 'Sahibi');
        });

        $this->command?->info('Demo accounts seeded. Password for all: '.self::PASSWORD);
    }

    private function createUser(string $email, string $firstName, string $lastName): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([
            'status' => UserStatus::Active,
            'locale' => 'tr',
            'timezone' => 'Europe/Istanbul',
        ]);

        $user->password_hash = Hash::make(self::PASSWORD);
        $user->email_verified_at = now();
        $user->save();

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $firstName.' '.$lastName,
            ],
        );

        return $user;
    }

    private function grantPlatformRole(User $user, SystemRole $role): void
    {
        $roleModel = Role::query()
            ->where('slug', $role->value)
            ->where('scope', $role->scope()->value)
            ->firstOrFail();

        UserRole::query()->firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'role_id' => $roleModel->getKey(),
                'organization_id' => null,
            ],
            ['granted_at' => now()],
        );
    }

    private function createSeller(
        string $name,
        string $slug,
        string $ownerEmail,
        string $firstName,
        string $lastName,
    ): void {
        $owner = $this->createUser($ownerEmail, $firstName, $lastName);

        /** @var Organization $organization */
        $organization = Organization::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => OrganizationType::Seller,
                'status' => OrganizationStatus::Active,
                'owner_user_id' => $owner->getKey(),
            ],
        );

        OrganizationUser::query()->updateOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'user_id' => $owner->getKey(),
            ],
            [
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ],
        );

        $role = Role::query()
            ->where('slug', SystemRole::SellerOwner->value)
            ->where('scope', SystemRole::SellerOwner->scope()->value)
            ->firstOrFail();

        UserRole::query()->firstOrCreate(
            [
                'user_id' => $owner->getKey(),
                'role_id' => $role->getKey(),
                'organization_id' => $organization->getKey(),
            ],
            ['granted_at' => now()],
        );
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the permission and role tables with the enums that define them.
 *
 * Idempotent and safe to re-run on every deploy: permissions are upserted, role
 * membership is replaced wholesale, and nothing is deleted — removing a permission
 * that existing grants still reference would silently revoke access in production.
 * Retiring one is a deliberate migration, not a seeder side effect.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = $this->syncPermissions();
            $this->syncRoles($permissions);
        });
    }

    /**
     * @return array<string, string> permission name => id
     */
    private function syncPermissions(): array
    {
        $ids = [];

        foreach (PermissionEnum::cases() as $case) {
            /** @var Permission $permission */
            $permission = Permission::query()->updateOrCreate(
                ['name' => $case->value],
                ['group' => $case->group(), 'description' => $case->description()],
            );

            $ids[$case->value] = (string) $permission->getKey();
        }

        return $ids;
    }

    /**
     * @param  array<string, string>  $permissionIds
     */
    private function syncRoles(array $permissionIds): void
    {
        foreach (SystemRole::cases() as $case) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['slug' => $case->value, 'scope' => $case->scope()->value],
                ['name' => $case->label(), 'is_system' => true],
            );

            $attach = [];

            foreach ($case->permissions() as $permission) {
                $attach[] = $permissionIds[$permission->value];
            }

            // sync() so a permission removed from the enum is also removed from the
            // role; the permission row itself survives for auditing.
            $role->permissions()->sync($attach);
        }
    }
}

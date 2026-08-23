<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Entry point for seeding.
 *
 * Split deliberately: reference data (roles, permissions) is required in every
 * environment including production, while demo accounts exist only where losing them
 * costs nothing.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('production')) {
            $this->command?->info('Production environment: demo data skipped.');

            return;
        }

        $this->call(DemoAccountsSeeder::class);
    }
}

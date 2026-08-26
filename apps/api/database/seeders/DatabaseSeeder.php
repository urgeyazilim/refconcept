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
        $this->call(SellerAgreementsSeeder::class);
        $this->call(CatalogTaxonomySeeder::class);

        // Routes, models and prompts for all twelve AI tasks. Not demo data: a task
        // with no route is a feature that fails the first time somebody uses it.
        $this->call(AiGatewaySeeder::class);

        // Packages and the welcome bonus. Also not demo data: an AI feature with
        // nothing to buy and no way to try it is a feature nobody reaches.
        $this->call(CreditEconomySeeder::class);

        // A receiving account, so bank transfer is a working payment method on a fresh
        // stack rather than one that 503s the first time somebody picks it.
        $this->call(BankAccountSeeder::class);

        // A platform commission default, because a sale with no rate is a sale whose
        // commission is a guess.
        $this->call(CommissionSeeder::class);

        if (app()->environment('production')) {
            $this->command?->info('Production environment: demo data skipped.');

            return;
        }

        $this->call(DemoAccountsSeeder::class);

        // Needs the demo sellers and the taxonomy above it, so it runs last.
        $this->call(DemoCatalogSeeder::class);
    }
}

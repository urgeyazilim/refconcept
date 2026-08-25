<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Credits\Models\CreditPromotion;
use Illuminate\Database\Seeder;

/**
 * What credits cost, and the welcome bonus.
 *
 * Not demo data: an AI feature with nothing to buy and no way to try it is a feature
 * nobody reaches. The packages are placeholders in the sense that the prices are a first
 * guess — but the shape is real, and an operator changes the numbers from the console.
 *
 * Idempotent on `code`, because this runs on every seed and a second copy of a package
 * would be a second row in the shop window.
 */
final class CreditEconomySeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Larger packages carry a bonus rather than a discount on the headline price.
         * The two are arithmetically similar and read very differently: "1000 + 200
         * hediye" is a gift, and "%17 indirim" invites the question of what the real
         * price is.
         */
        $packages = [
            [
                'code' => 'starter',
                'name' => 'Başlangıç',
                'description' => 'Bir odayı denemek için yeterli.',
                'credits' => 100,
                'bonus_credits' => 0,
                'price_minor' => 19_900,
                'validity_days' => 365,
                'position' => 10,
            ],
            [
                'code' => 'home',
                'name' => 'Ev',
                'description' => 'Birkaç odayı baştan sona tasarlamak için.',
                'credits' => 500,
                'bonus_credits' => 50,
                'price_minor' => 89_900,
                'validity_days' => 365,
                'is_featured' => true,
                'position' => 20,
            ],
            [
                'code' => 'studio',
                'name' => 'Stüdyo',
                'description' => 'Düzenli çalışanlar ve iç mimarlar için.',
                'credits' => 2_000,
                'bonus_credits' => 400,
                'price_minor' => 299_900,
                'validity_days' => 730,
                'position' => 30,
            ],
        ];

        foreach ($packages as $package) {
            CreditPackage::query()->updateOrCreate(
                ['code' => $package['code']],
                [...$package, 'currency' => 'TRY', 'is_active' => true],
            );
        }

        /*
         * The welcome bonus. Enough to analyse a room and produce a draft render, which
         * is the smallest amount that lets somebody see what the product actually does —
         * a bonus that runs out before the first result is worse than none.
         */
        CreditPromotion::query()->updateOrCreate(
            ['code' => 'HOSGELDIN'],
            [
                'name' => 'Hoş geldin kredisi',
                'description' => 'Yeni hesaplara ilk tasarımını denemesi için.',
                'credits' => 25,
                'validity_days' => 90,
                'max_per_user' => 1,
                'new_accounts_only' => true,
                'is_active' => true,
            ],
        );
    }
}

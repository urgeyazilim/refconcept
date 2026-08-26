<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Administration\Models\FeatureFlag;
use App\Domains\Administration\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * The switches an operator is allowed to reach, and nothing else.
 *
 * Deliberately short. Every row here is read by something — the hold period by the
 * settlement builder, the return window by the return service, each flag by the feature
 * that it gates — because a settings screen full of values nothing consults tells whoever
 * used it that they changed the platform when they did not.
 *
 * Values are left null on purpose. Null means "whatever the environment says", so a fresh
 * stack runs on its configured defaults and the row exists only to let somebody override
 * one without a deploy. See PlatformSettings for the order.
 *
 * Reference data rather than demo data: an operator's screen should look the same in
 * production as it does locally.
 */
final class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->settings() as $setting) {
            SystemSetting::query()->firstOrCreate(['key' => $setting['key']], $setting);
        }

        foreach ($this->flags() as $flag) {
            FeatureFlag::query()->firstOrCreate(['key' => $flag['key']], $flag);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settings(): array
    {
        return [
            [
                'key' => 'settlement.hold_days',
                'group' => 'finance',
                'label' => 'Hakediş bekleme süresi (gün)',
                'description' => 'Teslimattan sonra satıcıya ödeme yapılmadan önce beklenen gün sayısı. '
                    .'İade süresinden kısa bir değer kullanılmaz.',
                'type' => 'integer',
            ],
            [
                'key' => 'returns.window_days',
                'group' => 'finance',
                'label' => 'İade süresi (gün)',
                'description' => 'Müşterinin teslimattan sonra iade talebi açabileceği gün sayısı.',
                'type' => 'integer',
            ],
            [
                'key' => 'support.contact_email',
                'group' => 'general',
                'label' => 'Destek e-posta adresi',
                'description' => 'Müşteriye gösterilen destek adresi.',
                'type' => 'string',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flags(): array
    {
        return [
            [
                'key' => 'ai.design-generation',
                'name' => 'AI tasarım üretimi',
                'description' => 'Kapatıldığında müşteriler yeni tasarım işi başlatamaz; '
                    .'devam eden işler tamamlanır. Sağlayıcı arızasında ilk kapatılacak yer.',
                'is_enabled' => true,
                'rollout_percentage' => 100,
            ],
            [
                'key' => 'checkout.bank-transfer',
                'name' => 'Havale ile ödeme',
                'description' => 'Ödeme adımında havale seçeneğinin görünürlüğü.',
                'is_enabled' => true,
                'rollout_percentage' => 100,
            ],
            [
                'key' => 'seller.self-onboarding',
                'name' => 'Satıcı kendi başvurusunu tamamlayabilir',
                'description' => 'Kapatıldığında yeni satıcı başvuruları alınmaz; '
                    .'onaylanmış satıcılar etkilenmez.',
                'is_enabled' => true,
                'rollout_percentage' => 100,
            ],
        ];
    }
}

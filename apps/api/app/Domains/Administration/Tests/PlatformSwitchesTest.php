<?php

declare(strict_types=1);

use App\Domains\Administration\Models\FeatureFlag;
use App\Domains\Administration\Models\SystemSetting;
use App\Domains\Administration\Services\Features;
use App\Domains\Administration\Services\PlatformSettings;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Ai\Services\AiJobDispatcher;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Finance\Services\SettlementEligibility;
use App\Domains\Fulfilment\Services\ReturnService;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Services\GatewayRegistry;
use Database\Seeders\AiGatewaySeeder;
use Database\Seeders\PlatformSettingsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The switches do something.
 *
 * A settings screen that writes rows nothing reads is worse than no screen: it tells
 * whoever used it that they changed the platform, and they will act on that belief. These
 * tests exist so that stays true — each one flips a switch through the admin API and then
 * asks the service that is supposed to obey it.
 *
 * The other half is what happens when a row is missing. A flag with no row is **on**: a
 * feature that switched itself off because somebody forgot to seed it would be an outage
 * caused by the safety mechanism. A setting with no value falls back to the environment,
 * so a fresh stack runs on its configured defaults.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformSettingsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->admin, SystemRole::SuperAdmin);

    $this->features = app(Features::class);
    $this->settings = app(PlatformSettings::class);
});

/** Flips a flag through the API, the way an operator would. */
function setFlag(string $key, bool $enabled, int $rollout = 100): void
{
    $flag = FeatureFlag::query()->where('key', $key)->firstOrFail();

    test()->actingAs(test()->admin)
        ->patchJson('/api/v1/admin/system/flags/'.$flag->getKey(), [
            'key' => $flag->key,
            'name' => $flag->name,
            'is_enabled' => $enabled,
            'rollout_percentage' => $rollout,
        ])
        ->assertOk();
}

/** Sets a value through the API, the way an operator would. */
function setSetting(string $key, mixed $value): void
{
    $setting = SystemSetting::query()->where('key', $key)->firstOrFail();

    test()->actingAs(test()->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$setting->getKey(), ['value' => $value])
        ->assertOk();
}

// --- flags ------------------------------------------------------------------------

it('stops new AI work when the AI flag is turned off', function (): void {
    $this->seed(AiGatewaySeeder::class);

    $customer = User::factory()->create();

    setFlag('ai.design-generation', false);

    /*
     * Refused at the door rather than queued and failed. A customer pressing "render"
     * should be told the feature is off while they are still looking at the button, not
     * handed a job id to poll for a failure that is already known.
     */
    expect(fn () => app(AiJobDispatcher::class)->accept(
        AiTask::RoomAnalysis,
        ['prompt' => 'test'],
        $customer,
    ))->toThrow(AiJobRefused::class);

    setFlag('ai.design-generation', true);

    // Billed elsewhere, so the assertion is about the flag rather than about a balance.
    $job = app(AiJobDispatcher::class)->accept(
        AiTask::RoomAnalysis,
        ['prompt' => 'test'],
        $customer,
        creditCostOverride: 0,
    );

    expect($job->exists)->toBeTrue();
});

it('hides bank transfer from checkout when its flag is off', function (): void {
    expect(app(GatewayRegistry::class)->available())->toContain('bank_transfer');

    setFlag('checkout.bank-transfer', false);

    expect(app(GatewayRegistry::class)->available())->not->toContain('bank_transfer')
        ->and(app(GatewayRegistry::class)->isEnabled('bank_transfer'))->toBeFalse();
});

it('still reaches the adapter for a payment already taken through a disabled method', function (): void {
    setFlag('checkout.bank-transfer', false);

    /*
     * Switching a method off must not strand the money already taken through it. A
     * refund or a late reconciliation still has to find the adapter that understands
     * those payments; only *starting* a new one is gated.
     */
    expect(app(GatewayRegistry::class)->forExistingPayment('bank_transfer')->name())
        ->toBe('bank_transfer');
});

it('closes new seller applications when self-onboarding is off', function (): void {
    $applicant = User::factory()->create();
    $applicant->forceFill(['email_verified_at' => now()])->save();

    setFlag('seller.self-onboarding', false);

    $this->actingAs($applicant)
        ->postJson('/api/v1/seller/application', [
            'company_name' => 'Kapalı Kapı A.Ş.',
            'display_name' => 'Kapalı Kapı',
            'legal_form' => 'anonim_sirket',
            'contact_email' => 'basvuru@kapalikapi.test',
            'contact_phone' => '+90 212 000 00 00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.application.0', 'Yeni satıcı başvuruları şu anda alınmıyor.');
});

it('treats a flag with no row as on', function (): void {
    /*
     * The decision that matters. A feature that switched itself off because somebody
     * forgot to seed a row would be an outage caused by the safety mechanism — the worst
     * possible way to have one. Turning something off is a decision, and a decision has
     * a row.
     */
    expect($this->features->enabled('a.flag.that.was.never.created'))->toBeTrue();
});

it('keeps a user on the same side of a partial rollout', function (): void {
    setFlag('ai.design-generation', true, 50);

    $userId = (string) User::factory()->create()->getKey();
    $first = $this->features->enabled('ai.design-generation', $userId);

    // Not re-rolled per request: showing somebody a feature and taking it away mid-journey
    // is worse than never shipping it to them.
    $this->features->forget('ai.design-generation');

    expect($this->features->enabled('ai.design-generation', $userId))->toBe($first);
});

it('agrees with the model about who is in a partial rollout', function (): void {
    setFlag('ai.design-generation', true, 37);

    $flag = FeatureFlag::query()->where('key', 'ai.design-generation')->firstOrFail();

    /*
     * The service answers from cached scalars and the model from itself. They are two
     * pieces of arithmetic, and a rollout that disagreed with itself would move somebody
     * in and out of a feature depending on which code path asked.
     */
    foreach (User::factory()->count(12)->create() as $user) {
        $id = (string) $user->getKey();

        $this->features->forget('ai.design-generation');

        expect($this->features->enabled('ai.design-generation', $id))->toBe($flag->isOnFor($id));
    }
});

it('survives a flag that has been through the cache', function (): void {
    // Regression: the model itself used to be cached, and came back from a shared store as
    // an incomplete class — a fatal error inside the check that guards a feature.
    expect($this->features->enabled('seller.self-onboarding'))->toBeTrue()
        ->and($this->features->enabled('seller.self-onboarding'))->toBeTrue();
});

// --- settings ---------------------------------------------------------------------

it('lengthens the return window when an operator changes the setting', function (): void {
    expect(app(ReturnService::class)->windowDays())->toBe(14);

    setSetting('returns.window_days', 30);

    expect(app(ReturnService::class)->windowDays())->toBe(30);
});

it('lengthens the payout hold with the return window', function (): void {
    setSetting('returns.window_days', 45);

    /*
     * The hold covers the window. A configuration where it is shorter would pay a seller
     * while the customer can still send everything back — so changing one moves the other
     * rather than quietly leaving a gap.
     */
    expect(app(SettlementEligibility::class)->holdDays())->toBe(45);
});

it('falls back to the environment when a setting has no value', function (): void {
    // Seeded rows are deliberately null: the row exists to allow an override, not to
    // duplicate the configured default in a second place.
    expect($this->settings->integer('returns.window_days', 14))->toBe(14)
        ->and($this->settings->integer('a.key.with.no.row', 7))->toBe(7);
});

it('refuses a value of the wrong type', function (): void {
    $setting = SystemSetting::query()->where('key', 'returns.window_days')->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$setting->getKey(), ['value' => 'iki hafta'])
        ->assertStatus(422);

    // And the stored value is untouched, so a rejected edit cannot half-apply.
    expect($setting->fresh()?->value)->toBeNull();
});

it('never returns a secret value', function (): void {
    SystemSetting::query()->create([
        'key' => 'provider.api_token',
        'group' => 'integrations',
        'label' => 'Sağlayıcı anahtarı',
        'type' => 'string',
        'value' => 'gercek-anahtar-degeri',
        'is_secret' => true,
    ]);

    $row = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/system/settings')->json('data'))
        ->firstWhere('key', 'provider.api_token');

    /*
     * Not even to whoever set it. A settings screen that prints an API token has
     * published it to everybody who can open the page, and "it was already stored" is no
     * comfort afterwards.
     */
    expect($row['value'])->toBeNull()
        ->and($row['is_set'])->toBeTrue();
});

it('keeps a secret out of the audit log as well', function (): void {
    $setting = SystemSetting::query()->create([
        'key' => 'provider.api_secret',
        'group' => 'integrations',
        'label' => 'Sağlayıcı sırrı',
        'type' => 'string',
        'value' => 'eski-sir',
        'is_secret' => true,
    ]);

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/system/settings/'.$setting->getKey(), ['value' => 'yeni-sir'])
        ->assertOk();

    $entry = AuditLog::query()
        ->where('action', 'platform.setting.changed')
        ->latest('created_at')
        ->firstOrFail();

    // An audit trail is read by more people than a secret store is.
    expect(json_encode($entry->changes))->not->toContain('eski-sir')
        ->and(json_encode($entry->changes))->not->toContain('yeni-sir');
});

it('lets only a super admin reach the switches', function (): void {
    $operator = User::factory()->create();
    $operator->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($operator, SystemRole::Operator);

    /*
     * The one power on this screen whose blast radius is the whole platform. Turning a
     * feature on for everybody is a release decision rather than an operational one.
     */
    $this->actingAs($operator)->getJson('/api/v1/admin/system/flags')->assertForbidden();
    $this->actingAs($operator)->getJson('/api/v1/admin/system/settings')->assertForbidden();

    // The same operator can still see what failed overnight, which is their job.
    $this->actingAs($operator)->getJson('/api/v1/admin/system/jobs')->assertOk();
});

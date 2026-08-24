<?php

declare(strict_types=1);

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Identity\Services\AccessControl;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Sellers\Enums\ApplicationStatus;
use App\Domains\Sellers\Enums\SellerStatus;
use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerStatusHistory;
use App\Domains\Sellers\Notifications\ApplicationApproved;
use App\Domains\Sellers\Notifications\ApplicationRejected;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SellerAgreementsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SellerAgreementsSeeder::class);

    $this->operator = User::factory()->create();
    grantRole($this->operator, SystemRole::Operator);

    $this->applicant = User::factory()->create();

    $this->application = SellerApplication::factory()
        ->complete()
        ->submitted()
        ->create(['applicant_user_id' => $this->applicant->getKey()]);
});

function grantRole(User $user, SystemRole $role): void
{
    $model = Role::query()
        ->where('slug', $role->value)
        ->where('scope', $role->scope()->value)
        ->firstOrFail();

    UserRole::query()->create([
        'user_id' => $user->getKey(),
        'role_id' => $model->getKey(),
        'organization_id' => null,
        'granted_at' => now(),
    ]);
}

it('lists applications awaiting review by default', function (): void {
    // A withdrawn application is nobody's work item.
    SellerApplication::factory()->create(['applicant_user_id' => User::factory()->create()->getKey()]);

    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/seller-applications')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($this->application->getKey());
});

it('moves an application into review', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/review")
        ->assertOk()
        ->assertJsonPath('data.status', ApplicationStatus::InReview->value);
});

it('creates the organization, seller, membership and role grant on approval', function (): void {
    $response = $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Belgeler eksiksiz ve doğrulandı.',
            'commission_bps' => 1250,
        ])
        ->assertOk();

    $seller = Seller::query()->firstOrFail();
    $organization = Organization::query()->firstOrFail();

    expect($response->json('data.seller_code'))->toBe($seller->seller_code)
        ->and($seller->status)->toBe(SellerStatus::Active)
        ->and($seller->default_commission_bps)->toBe(1250)
        ->and($seller->organization_id)->toBe($organization->getKey())
        ->and($organization->owner_user_id)->toBe($this->applicant->getKey());

    // Membership says which tenant, the role grant says with what authority. Without
    // both, the applicant would own a company they cannot act inside.
    $access = app(AccessControl::class);

    expect($access->belongsToOrganization($this->applicant, (string) $organization->getKey()))->toBeTrue()
        ->and($access->hasPermission(
            $this->applicant,
            Permission::SellerProfileManage,
            (string) $organization->getKey(),
        ))->toBeTrue();
});

it('notifies the applicant on approval', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Belgeler eksiksiz ve doğrulandı.',
        ])
        ->assertOk();

    Notification::assertSentTo($this->applicant, ApplicationApproved::class);
});

it('records the approval in the audit trail with its reason', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Vergi levhası ve imza sirküleri doğrulandı.',
        ])
        ->assertOk();

    $entry = AuditLog::query()->where('action', 'sellers.application.approved')->firstOrFail();

    expect($entry->actor_id)->toBe($this->operator->getKey())
        ->and($entry->reason)->toBe('Vergi levhası ve imza sirküleri doğrulandı.');
});

it('demands a reason for approval', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect(Seller::query()->count())->toBe(0);
});

it('rejects an application with its reason and notifies the applicant', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/reject", [
            'reason' => 'İmza sirküleri okunamıyor, lütfen yeniden yükleyin.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', ApplicationStatus::Rejected->value);

    Notification::assertSentTo($this->applicant, ApplicationRejected::class);

    expect($this->application->fresh()->decision_reason)
        ->toBe('İmza sirküleri okunamıyor, lütfen yeniden yükleyin.')
        ->and(Seller::query()->count())->toBe(0);
});

it('refuses to decide an application twice', function (): void {
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Belgeler eksiksiz ve doğrulandı.',
        ])
        ->assertOk();

    // A decided application is final; a second approval would create a second seller.
    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Tekrar onaylama denemesi yapılıyor.',
        ])
        ->assertStatus(422);

    expect(Seller::query()->count())->toBe(1);
});

it('refuses to approve an application that is not complete', function (): void {
    $incomplete = SellerApplication::factory()
        ->submitted()
        ->create(['applicant_user_id' => User::factory()->create()->getKey()]);

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/seller-applications/{$incomplete->getKey()}/approve", [
            'reason' => 'Yine de onaylanmaya çalışılıyor.',
        ])
        ->assertStatus(422);

    expect(Seller::query()->count())->toBe(0);
});

it('does not let an applicant decide their own application', function (): void {
    $this->actingAs($this->applicant)
        ->postJson("/api/v1/admin/seller-applications/{$this->application->getKey()}/approve", [
            'reason' => 'Kendi başvurumu onaylıyorum.',
        ])
        ->assertForbidden();

    expect(Seller::query()->count())->toBe(0);
});

it('suspends a seller with a reason and stops them trading', function (): void {
    $this->actingAs($this->operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->application->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
    );

    $seller = Seller::query()->firstOrFail();

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/sellers/{$seller->getKey()}/suspend", [
            'reason' => 'Tekrarlanan teslimat başarısızlığı nedeniyle askıya alındı.',
        ])
        ->assertOk();

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::Suspended)
        ->and($seller->canTrade())->toBeFalse()
        ->and($seller->suspended_at)->not->toBeNull();

    $history = SellerStatusHistory::query()
        ->where('seller_id', $seller->getKey())
        ->orderByDesc('changed_at')
        ->first();

    expect($history->to_status)->toBe(SellerStatus::Suspended->value)
        ->and($history->reason)->toContain('teslimat');
});

it('never lets a seller lift their own suspension', function (): void {
    $this->actingAs($this->operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->application->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
    );

    $seller = Seller::query()->firstOrFail();

    $this->actingAs($this->operator)->postJson("/api/v1/admin/sellers/{$seller->getKey()}/suspend", [
        'reason' => 'Mevzuata aykırı ürün listelendiği tespit edildi.',
    ]);

    // The seller owner is a member of this organization and can read the seller, but
    // suspension is platform economics: self-service would make it meaningless.
    $this->actingAs($this->applicant)
        ->postJson("/api/v1/admin/sellers/{$seller->getKey()}/reactivate", [
            'reason' => 'Kendi hesabımı yeniden açıyorum.',
        ])
        ->assertForbidden();

    expect($seller->fresh()->status)->toBe(SellerStatus::Suspended);
});

it('reactivates a suspended seller and records both transitions', function (): void {
    $this->actingAs($this->operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->application->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
    );

    $seller = Seller::query()->firstOrFail();

    $this->actingAs($this->operator)->postJson("/api/v1/admin/sellers/{$seller->getKey()}/suspend", [
        'reason' => 'İnceleme süresince askıya alındı.',
    ]);

    $this->actingAs($this->operator)
        ->postJson("/api/v1/admin/sellers/{$seller->getKey()}/reactivate", [
            'reason' => 'İnceleme tamamlandı, ihlal bulunmadı.',
        ])
        ->assertOk();

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::Active)
        ->and($seller->suspended_at)->toBeNull()
        ->and(SellerStatusHistory::query()->where('seller_id', $seller->getKey())->count())->toBe(3);
});

it('audits a commission change with before and after values', function (): void {
    $this->actingAs($this->operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->application->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.', 'commission_bps' => 1200],
    );

    $seller = Seller::query()->firstOrFail();

    $this->actingAs($this->operator)
        ->patchJson("/api/v1/admin/sellers/{$seller->getKey()}/commission", [
            'commission_bps' => 900,
            'reason' => 'Kampanya dönemi için indirimli oran uygulanıyor.',
        ])
        ->assertOk();

    $entry = AuditLog::query()->where('action', 'sellers.seller.commission_changed')->firstOrFail();

    expect($entry->changes['default_commission_bps']['from'])->toBe(1200)
        ->and($entry->changes['default_commission_bps']['to'])->toBe(900)
        ->and($seller->fresh()->default_commission_bps)->toBe(900);
});

it('falls back to the platform default commission when the seller has none', function (): void {
    $this->actingAs($this->operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->application->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
    );

    $seller = Seller::query()->firstOrFail();

    expect($seller->default_commission_bps)->toBeNull()
        ->and($seller->effectiveCommissionBps())
        ->toBe((int) config('refconcept.commission.platform_default_bps'));
});

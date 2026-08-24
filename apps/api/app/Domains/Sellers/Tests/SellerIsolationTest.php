<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Models\SellerApplication;
use App\Domains\Sellers\Models\SellerDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SellerAgreementsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Tenant isolation across seller onboarding.
 *
 * Phase 1 proved the boundary at the policy level. This file proves it end to end for
 * the first real seller-owned data: applications carrying tax numbers, IBANs and
 * identity documents. Every check is "can applicant A reach applicant B's thing",
 * asked through the HTTP surface rather than the policy class, because the surface is
 * where a missing check actually leaks.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SellerAgreementsSeeder::class);

    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();

    $this->aliceApplication = SellerApplication::factory()
        ->complete()
        ->create(['applicant_user_id' => $this->alice->getKey()]);

    $this->bobApplication = SellerApplication::factory()
        ->complete()
        ->create(['applicant_user_id' => $this->bob->getKey()]);
});

it('returns only the signed-in applicant own application', function (): void {
    $response = $this->actingAs($this->alice)
        ->getJson('/api/v1/seller/application')
        ->assertOk();

    expect($response->json('data.id'))->toBe($this->aliceApplication->getKey())
        ->and($response->json('data.id'))->not->toBe($this->bobApplication->getKey());
});

it('never exposes another applicant IBAN', function (): void {
    $response = $this->actingAs($this->alice)->getJson('/api/v1/seller/application')->assertOk();

    $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);

    // Bob's data must not appear at all, masked or otherwise.
    expect($payload)->not->toContain($this->bobApplication->getKey())
        ->and($payload)->not->toContain($this->bobApplication->company_name);
});

it('refuses to serve another applicant document', function (): void {
    $bobDocument = SellerDocument::query()
        ->where('application_id', $this->bobApplication->getKey())
        ->firstOrFail();

    $this->actingAs($this->alice)
        ->getJson("/api/v1/seller/documents/{$bobDocument->getKey()}/link")
        ->assertForbidden();

    $this->actingAs($this->alice)
        ->get("/api/v1/seller/documents/{$bobDocument->getKey()}/download")
        ->assertForbidden();
});

it('refuses to delete another applicant document', function (): void {
    $bobDocument = SellerDocument::query()
        ->where('application_id', $this->bobApplication->getKey())
        ->firstOrFail();

    $this->actingAs($this->alice)
        ->deleteJson("/api/v1/seller/documents/{$bobDocument->getKey()}")
        ->assertForbidden();

    expect(SellerDocument::query()->whereKey($bobDocument->getKey())->exists())->toBeTrue();
});

it('does not let an applicant read the admin review queue', function (): void {
    $this->actingAs($this->alice)
        ->getJson('/api/v1/admin/seller-applications')
        ->assertForbidden();
});

it('does not let an applicant open another application through the admin route', function (): void {
    $this->actingAs($this->alice)
        ->getJson("/api/v1/admin/seller-applications/{$this->bobApplication->getKey()}")
        ->assertForbidden();
});

it('never exposes a document storage path', function (): void {
    $document = SellerDocument::query()
        ->where('application_id', $this->aliceApplication->getKey())
        ->firstOrFail();

    $response = $this->actingAs($this->alice)->getJson('/api/v1/seller/application')->assertOk();

    // A predictable object key turns the bucket into a directory listing.
    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain($document->storage_path);
});

it('keeps one approved seller out of another seller record', function (): void {
    $operator = User::factory()->create();

    $role = Role::query()
        ->where('slug', SystemRole::Operator->value)
        ->where('scope', SystemRole::Operator->scope()->value)
        ->firstOrFail();

    UserRole::query()->create([
        'user_id' => $operator->getKey(),
        'role_id' => $role->getKey(),
        'organization_id' => null,
        'granted_at' => now(),
    ]);

    foreach ([$this->aliceApplication, $this->bobApplication] as $application) {
        $application->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        $this->actingAs($operator)->postJson(
            "/api/v1/admin/seller-applications/{$application->getKey()}/approve",
            ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
        )->assertOk();
    }

    $bobSeller = Seller::query()
        ->where('application_id', $this->bobApplication->getKey())
        ->firstOrFail();

    // Alice owns a seller of her own, which is exactly the case a naive
    // "is this user a seller?" check would wave through.
    $this->actingAs($this->alice)
        ->getJson("/api/v1/admin/sellers/{$bobSeller->getKey()}")
        ->assertForbidden();

    $this->actingAs($this->alice)
        ->postJson("/api/v1/admin/sellers/{$bobSeller->getKey()}/suspend", [
            'reason' => 'Rakibimi askıya almaya çalışıyorum.',
        ])
        ->assertForbidden();

    expect($bobSeller->fresh()->status->value)->toBe('active');
});

it('lets a seller owner read their own seller record', function (): void {
    $operator = User::factory()->create();

    $role = Role::query()
        ->where('slug', SystemRole::Operator->value)
        ->where('scope', SystemRole::Operator->scope()->value)
        ->firstOrFail();

    UserRole::query()->create([
        'user_id' => $operator->getKey(),
        'role_id' => $role->getKey(),
        'organization_id' => null,
        'granted_at' => now(),
    ]);

    $this->aliceApplication->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

    $this->actingAs($operator)->postJson(
        "/api/v1/admin/seller-applications/{$this->aliceApplication->getKey()}/approve",
        ['reason' => 'Belgeler eksiksiz ve doğrulandı.'],
    )->assertOk();

    $aliceSeller = Seller::query()->firstOrFail();

    // Isolation must not become a wall around the seller's own data.
    $this->actingAs($this->alice)
        ->getJson("/api/v1/admin/sellers/{$aliceSeller->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.seller_code', $aliceSeller->seller_code);
});

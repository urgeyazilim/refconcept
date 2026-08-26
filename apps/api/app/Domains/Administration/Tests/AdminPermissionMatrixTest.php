<?php

declare(strict_types=1);

use App\Domains\Administration\Services\AdminPermissionMatrix;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/**
 * The Phase 18 gate, first half: the permission matrix.
 *
 * The claim being tested is not "these endpoints are protected" — that is a list somebody
 * has to keep up to date. It is **"no administrative endpoint can exist without a decision
 * about who may call it"**, which is a property, and the only kind of guarantee worth
 * having about authorisation.
 *
 * The mechanism is a matrix consulted by middleware on the whole API group. An admin route
 * with no entry is refused at runtime and fails here at build time, so the mistake is
 * caught long before it is exploited.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->matrix = app(AdminPermissionMatrix::class);

    $this->analyst = User::factory()->create();
    $this->analyst->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->analyst, SystemRole::Analyst);

    $this->operator = User::factory()->create();
    $this->operator->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->operator, SystemRole::Operator);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->forceFill(['email_verified_at' => now()])->save();
    grantPlatformRole($this->superAdmin, SystemRole::SuperAdmin);

    $this->customer = User::factory()->create();
    $this->customer->forceFill(['email_verified_at' => now()])->save();
});

// --- the property ---------------------------------------------------------------

it('leaves no administrative route unclaimed', function (): void {
    $uncovered = $this->matrix->uncovered();

    /*
     * The gate. A non-empty list means somebody added an admin endpoint without deciding
     * who may call it — the mistake that is invisible until it is exploited.
     */
    expect($uncovered)->toBe([], 'yetki tanımı olmayan yönetim uçları: '.implode(', ', $uncovered));
});

it('covers every admin route the router knows about', function (): void {
    $adminRoutes = collect(Route::getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/'))
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->unique();

    // A meaningful count, so this cannot pass by finding nothing.
    expect($adminRoutes->count())->toBeGreaterThan(50);

    foreach ($adminRoutes as $name) {
        expect($this->matrix->permissionFor($name))
            ->not->toBeNull("yetki tanımı eksik: {$name}");
    }
});

it('lets a more specific rule outrank a broader one', function (): void {
    /*
     * Reading a settlement and approving one live under the same prefix and are different
     * powers. A matrix that could not express that would hand the second to anybody who
     * needed the first.
     */
    expect($this->matrix->permissionFor('v1.admin.finance.settlements.index'))
        ->toBe(Permission::PaymentsView)
        ->and($this->matrix->permissionFor('v1.admin.finance.settlements.approve'))
        ->toBe(Permission::PaymentsSettle);

    expect($this->matrix->permissionFor('v1.admin.sellers.index'))->toBe(Permission::SellersView)
        ->and($this->matrix->permissionFor('v1.admin.sellers.suspend'))->toBe(Permission::SellersManage);
});

// --- the roles behave as the matrix says ------------------------------------------

it('lets an analyst read and refuses every verb', function (): void {
    // Reads.
    $this->actingAs($this->analyst)->getJson('/api/v1/admin/orders')->assertOk();
    $this->actingAs($this->analyst)->getJson('/api/v1/admin/analytics/overview')->assertOk();
    $this->actingAs($this->analyst)->getJson('/api/v1/admin/audit')->assertOk();
    $this->actingAs($this->analyst)->getJson('/api/v1/admin/sellers')->assertOk();

    // Verbs.
    $this->actingAs($this->analyst)
        ->postJson('/api/v1/admin/finance/settlements/build')
        ->assertForbidden();

    $this->actingAs($this->analyst)
        ->getJson('/api/v1/admin/credits/packages')
        ->assertForbidden();

    $this->actingAs($this->analyst)
        ->getJson('/api/v1/admin/system/flags')
        ->assertForbidden();
});

it('lets an operator work but not change the platform itself', function (): void {
    $this->actingAs($this->operator)->getJson('/api/v1/admin/credits/packages')->assertOk();
    $this->actingAs($this->operator)->getJson('/api/v1/admin/payments/transfers')->assertOk();
    $this->actingAs($this->operator)->getJson('/api/v1/admin/system/jobs')->assertOk();

    /*
     * Turning a feature on for everybody is a release decision rather than an operational
     * one, and the one power on an operator's screen whose blast radius is the whole
     * platform.
     */
    $this->actingAs($this->operator)->getJson('/api/v1/admin/system/flags')->assertForbidden();
    $this->actingAs($this->operator)->getJson('/api/v1/admin/system/settings')->assertForbidden();
});

it('lets a super admin through everything', function (): void {
    $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/system/flags')->assertOk();
    $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/system/settings')->assertOk();
    $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/analytics/overview')->assertOk();
});

it('keeps a customer out of every administrative surface', function (): void {
    foreach ([
        '/api/v1/admin/orders',
        '/api/v1/admin/audit',
        '/api/v1/admin/analytics/overview',
        '/api/v1/admin/system/flags',
        '/api/v1/admin/system/jobs',
        '/api/v1/admin/sellers',
        '/api/v1/admin/credits/packages',
        '/api/v1/admin/finance/overview',
    ] as $path) {
        $this->actingAs($this->customer)->getJson($path)->assertForbidden();
    }
});

it('refuses a signed-out caller before it asks about permissions', function (): void {
    $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
});

it('tells an operator what they may do', function (): void {
    $response = $this->actingAs($this->operator)
        ->getJson('/api/v1/admin/audit/matrix')
        ->assertOk();

    $granted = collect($response->json('data.permissions'))
        ->filter(fn (array $row): bool => $row['granted'])
        ->pluck('value');

    /*
     * The useful half of the screen: an operator looking at a button they cannot press
     * deserves to find out why from a page rather than from a 403.
     */
    expect($granted)->toContain('platform.payments.settle')
        ->and($granted)->not->toContain('platform.flags.manage')
        ->and($response->json('data.uncovered_routes'))->toBe([]);
});

it('applies to a route the matrix has never heard of', function (): void {
    // Registered into the `api` group, because that is where the matrix middleware lives
    // and a route outside it would prove nothing about routes inside it.
    Route::middleware(['api', 'auth:sanctum'])
        ->get('api/v1/admin/nonsense', fn (): array => ['ok' => true])
        ->name('v1.admin.nonsense');

    /*
     * Fails closed. "We have not decided who may do this yet" is much closer to "nobody"
     * than to "everybody", and this is the safety net under the coverage test above.
     */
    $this->actingAs($this->operator)->getJson('/api/v1/admin/nonsense')->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Domains\Administration\Enums\HealthStatus;
use Illuminate\Support\Facades\DB;

it('reports the platform as healthy when every dependency responds', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonPath('application', config('app.name'))
        ->assertJsonPath('environment', 'testing')
        ->assertJsonStructure([
            'status',
            'application',
            'environment',
            'version',
            'milestone',
            'timestamp',
            'checks' => [
                'database' => ['status'],
                'pgvector' => ['status'],
                'cache' => ['status'],
                'queue' => ['status'],
                'storage' => ['status'],
                'migrations' => ['status'],
            ],
        ]);

    expect($response->json('checks.database.status'))->toBe(HealthStatus::Ok->value)
        ->and($response->json('checks.cache.status'))->toBe(HealthStatus::Ok->value)
        ->and($response->json('checks.storage.status'))->toBe(HealthStatus::Ok->value)
        ->and($response->json('checks.migrations.status'))->toBe(HealthStatus::Ok->value);
});

it('runs against postgresql with the pgvector extension installed', function (): void {
    // The stack lock (19_TECH_STACK_LOCK.md) requires PostgreSQL, and Phase 9 product
    // matching requires pgvector. Catching a drifted test database here is far cheaper
    // than discovering it nine phases later.
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $extension = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

    expect($extension)->not->toBeNull();
});

it('exposes the health endpoint without authentication', function (): void {
    // Container orchestrators and CI probe this endpoint before any credential exists.
    $this->getJson('/api/health')->assertOk();
});

it('never leaks internal details in the public payload', function (): void {
    $payload = $this->getJson('/api/health')->json();

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('password')
        ->and($encoded)->not->toContain(config('database.connections.pgsql.password'));
});

<?php

declare(strict_types=1);

use App\Domains\Administration\DTOs\HealthCheckResult;
use App\Domains\Administration\Enums\HealthStatus;

it('collapses many statuses to the worst one', function (): void {
    expect(HealthStatus::worst(HealthStatus::Ok, HealthStatus::Ok))->toBe(HealthStatus::Ok)
        ->and(HealthStatus::worst(HealthStatus::Ok, HealthStatus::Degraded))->toBe(HealthStatus::Degraded)
        ->and(HealthStatus::worst(HealthStatus::Degraded, HealthStatus::Down))->toBe(HealthStatus::Down)
        ->and(HealthStatus::worst(HealthStatus::Down, HealthStatus::Ok))->toBe(HealthStatus::Down);
});

it('treats an empty status list as healthy', function (): void {
    expect(HealthStatus::worst())->toBe(HealthStatus::Ok);
});

it('serialises a check result without null noise', function (): void {
    $result = HealthCheckResult::ok('cache');

    expect($result->toArray())->toBe(['status' => 'ok']);
});

it('rounds durations to two decimals', function (): void {
    $result = new HealthCheckResult('database', HealthStatus::Ok, 'pgsql', 1.23456);

    expect($result->toArray())->toBe([
        'status' => 'ok',
        'message' => 'pgsql',
        'duration_ms' => 1.23,
    ]);
});

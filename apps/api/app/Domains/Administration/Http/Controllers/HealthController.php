<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Enums\HealthStatus;
use App\Domains\Administration\Services\HealthCheckRunner;
use Illuminate\Http\JsonResponse;

/**
 * Public readiness endpoint.
 *
 * Returns 200 while the platform can serve traffic (ok/degraded) and 503 when a
 * critical dependency is down, so container orchestrators and CI can gate on it.
 */
final class HealthController
{
    public function __construct(private readonly HealthCheckRunner $runner) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->runner->run();

        /** @var HealthStatus $status */
        $status = $result['status'];

        $payload = [
            'status' => $status->value,
            'application' => (string) config('app.name'),
            'environment' => (string) config('app.env'),
            'version' => (string) config('refconcept.version'),
            'milestone' => (string) config('refconcept.milestone'),
            'timestamp' => now()->toIso8601String(),
            'checks' => array_map(
                static fn ($check) => $check->toArray(),
                $result['checks'],
            ),
        ];

        return response()->json(
            $payload,
            $status === HealthStatus::Down ? 503 : 200,
        );
    }
}

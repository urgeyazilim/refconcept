<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Domains\Administration\DTOs\HealthCheckResult;
use App\Domains\Administration\Enums\HealthStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes every runtime dependency the platform needs before it can serve traffic.
 *
 * Used by `GET /api/health` (liveness/readiness for containers and CI) and by the
 * Phase 0 bootstrap gate. Each probe is isolated: one failing dependency reports its
 * own status instead of throwing the whole endpoint.
 */
final class HealthCheckRunner
{
    /** Probes considered fatal for readiness; others only degrade the overall status. */
    private const CRITICAL = ['database', 'cache', 'queue'];

    /**
     * @return array{status: HealthStatus, checks: array<string, HealthCheckResult>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->timed('database', fn () => $this->checkDatabase()),
            'pgvector' => $this->timed('pgvector', fn () => $this->checkPgVector()),
            'cache' => $this->timed('cache', fn () => $this->checkCache()),
            'queue' => $this->timed('queue', fn () => $this->checkQueue()),
            'storage' => $this->timed('storage', fn () => $this->checkStorage()),
            'migrations' => $this->timed('migrations', fn () => $this->checkMigrations()),
        ];

        return [
            'status' => $this->overall($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @param  callable(): HealthCheckResult  $probe
     */
    private function timed(string $name, callable $probe): HealthCheckResult
    {
        $startedAt = hrtime(true);

        try {
            $result = $probe();
        } catch (Throwable $e) {
            $result = HealthCheckResult::down($name, $this->safeMessage($e));
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        return new HealthCheckResult($result->name, $result->status, $result->message, $durationMs);
    }

    private function checkDatabase(): HealthCheckResult
    {
        DB::connection()->select('select 1');

        return HealthCheckResult::ok('database', DB::connection()->getDriverName());
    }

    /**
     * pgvector backs Phase 9 product matching. Missing it is not fatal for Phase 0
     * traffic, but it must be visible long before the matching work starts.
     */
    private function checkPgVector(): HealthCheckResult
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return HealthCheckResult::degraded('pgvector', 'non-postgres connection in use');
        }

        $installed = DB::connection()->select(
            "select extversion from pg_extension where extname = 'vector'"
        );

        if ($installed === []) {
            return HealthCheckResult::degraded('pgvector', 'vector extension not installed');
        }

        return HealthCheckResult::ok('pgvector', 'v'.$installed[0]->extversion);
    }

    private function checkCache(): HealthCheckResult
    {
        $key = 'health:cache:'.Str::random(12);
        $value = Str::random(16);

        Cache::put($key, $value, 10);
        $readBack = Cache::get($key);
        Cache::forget($key);

        if ($readBack !== $value) {
            return HealthCheckResult::down('cache', 'write/read mismatch');
        }

        return HealthCheckResult::ok('cache', (string) config('cache.default'));
    }

    /**
     * AI generation, payment reconciliation and settlement all run on queues, so an
     * unreachable queue backend is a hard failure even though HTTP still responds.
     */
    private function checkQueue(): HealthCheckResult
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return HealthCheckResult::degraded('queue', 'sync driver: jobs run inline');
        }

        if ($connection === 'redis') {
            Redis::connection(config('queue.connections.redis.connection', 'default'))->ping();
        }

        return HealthCheckResult::ok('queue', $connection);
    }

    private function checkStorage(): HealthCheckResult
    {
        $disk = (string) config('filesystems.default');

        /** @var Filesystem $filesystem */
        $filesystem = Storage::disk($disk);

        $path = 'health/'.Str::uuid()->toString().'.txt';
        $payload = 'refconcept-health';

        $filesystem->put($path, $payload);
        $readBack = $filesystem->get($path);
        $filesystem->delete($path);

        if ($readBack !== $payload) {
            return HealthCheckResult::down('storage', 'write/read mismatch on disk '.$disk);
        }

        return HealthCheckResult::ok('storage', $disk);
    }

    private function checkMigrations(): HealthCheckResult
    {
        if (! DB::connection()->getSchemaBuilder()->hasTable('migrations')) {
            return HealthCheckResult::down('migrations', 'migrations table missing');
        }

        $applied = DB::table('migrations')->count();

        return HealthCheckResult::ok('migrations', $applied.' applied');
    }

    /**
     * @param  array<string, HealthCheckResult>  $checks
     */
    private function overall(array $checks): HealthStatus
    {
        $status = HealthStatus::Ok;

        foreach ($checks as $name => $check) {
            $effective = in_array($name, self::CRITICAL, true)
                ? $check->status
                // A non-critical failure caps out at "degraded".
                : ($check->status === HealthStatus::Down ? HealthStatus::Degraded : $check->status);

            $status = HealthStatus::worst($status, $effective);
        }

        return $status;
    }

    /**
     * Never leak connection strings or credentials through a public endpoint.
     */
    private function safeMessage(Throwable $e): string
    {
        if (app()->hasDebugModeEnabled()) {
            return Str::limit($e->getMessage(), 200);
        }

        return class_basename($e);
    }
}

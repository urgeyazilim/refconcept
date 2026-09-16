<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Waits until the `ai` queue holds none of the named jobs.
 *
 * Why it exists: uploading a photograph queues a reading of the room twenty seconds later.
 * The end-to-end suite points that task at the local simulator for the run and puts the
 * real routing back in its teardown — and for a while the teardown ran before those twenty
 * seconds were up, so the reading fired against the real provider after the test that
 * caused it had passed, and was billed. Eight readings in one afternoon, a few kuruş each,
 * none of them approved. The teardown now calls this before restoring the routes: it looks
 * at the waiting, delayed and reserved sets of the queue and returns only when no
 * `AnalyseRoom` or `ClearRoomPhotograph` is left in any of them.
 *
 * Returns failure on timeout so the caller can say so; it never touches the jobs.
 */
final class AwaitAiQueueDrainCommand extends Command
{
    protected $signature = 'refconcept:await-ai-queue
        {--queue=ai : The queue to watch}
        {--jobs=AnalyseRoom,ClearRoomPhotograph,GenerateDesignVersion : Job class names (short) that must be gone}
        {--timeout=90 : Seconds to wait before giving up}';

    protected $description = 'Waits until the AI queue holds none of the named jobs';

    public function handle(): int
    {
        $queue = (string) $this->option('queue');
        $jobs = array_filter(array_map('trim', explode(',', (string) $this->option('jobs'))));
        $deadline = microtime(true) + (int) $this->option('timeout');

        do {
            $pending = $this->pending($queue, $jobs);

            if ($pending === 0) {
                $this->info(sprintf('%s kuyruğunda bekleyen okuma yok.', $queue));

                return self::SUCCESS;
            }

            $this->line(sprintf('%d iş bekliyor…', $pending));
            usleep(1_000_000);
        } while (microtime(true) < $deadline);

        $this->error(sprintf('%d iş hâlâ %s kuyruğunda; süre doldu.', $pending, $queue));

        return self::FAILURE;
    }

    /**
     * How many of the named jobs are waiting, delayed or being worked on.
     *
     * @param  array<int, string>  $jobs
     */
    private function pending(string $queue, array $jobs): int
    {
        $connection = Redis::connection((string) config('queue.connections.redis.connection', 'default'));
        $key = 'queues:'.$queue;

        $payloads = [
            ...$connection->lrange($key, 0, -1),
            ...$connection->zrange($key.':delayed', 0, -1),
            ...$connection->zrange($key.':reserved', 0, -1),
        ];

        $count = 0;

        foreach ($payloads as $payload) {
            foreach ($jobs as $job) {
                if (str_contains((string) $payload, $job)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }
}

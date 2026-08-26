<?php

declare(strict_types=1);

use App\Domains\Ai\Jobs\RunAiJob;
use App\Domains\Payments\Jobs\ProcessPaymentWebhook;
use App\Domains\Projects\Jobs\GenerateDesignVersion;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 21 gate, operations half: the platform can be run, not only used.
 *
 * These are the properties nobody checks because nothing fails when they are wrong — the
 * system is simply slower, or more expensive, or quietly missing an index — until a
 * Tuesday when it is all three at once.
 */

// --- queues -----------------------------------------------------------------------

it('keeps work somebody is waiting on off the slow queue', function (): void {
    /*
     * The finding this test came from: payment webhooks shared `default` with AI renders,
     * and an AI render can hold a worker for ten minutes. A payment confirmation queued
     * behind one is a customer staring at a spinner for reasons that have nothing to do
     * with their payment.
     *
     * Two queues, two worker processes. This test is what stops the third job somebody
     * adds from landing on the wrong one.
     */
    expect((new ProcessPaymentWebhook('event-id'))->queue)->toBe('payments');

    expect((new RunAiJob('job-id'))->queue)->toBe('ai')
        ->and((new GenerateDesignVersion('version-id'))->queue)->toBe('ai');
});

it('gives the slow jobs a single attempt and the fast ones several', function (): void {
    /*
     * One attempt for AI, because the gateway already retries with a policy that knows a
     * timeout from a refusal — a queue retry on top multiplies the two into a bill nobody
     * authorised.
     *
     * Eight for a payment webhook, because a webhook we failed to process is a customer
     * who paid and has nothing to show for it, and the provider will not keep resending
     * forever.
     */
    foreach ([RunAiJob::class, GenerateDesignVersion::class] as $slow) {
        expect((new ReflectionClass($slow))->getDefaultProperties()['tries'] ?? null)->toBe(1);
    }

    expect((new ProcessPaymentWebhook('event-id'))->tries)->toBeGreaterThanOrEqual(5);
});

it('backs a failing webhook off rather than hammering', function (): void {
    $backoff = (new ProcessPaymentWebhook('event-id'))->backoff;

    // A database briefly unavailable must not be hit by every queued webhook at once, and
    // the delays have to grow: a fixed retry interval is a thundering herd on a timer.
    expect($backoff)->toBeArray()
        ->and(count($backoff))->toBeGreaterThan(3)
        ->and($backoff[count($backoff) - 1])->toBeGreaterThan($backoff[0]);
});

// --- indexes ----------------------------------------------------------------------

it('has an index behind every query a customer waits on', function (): void {
    /*
     * Checked as a property rather than measured, because a sequential scan over a
     * thousand demo rows is fast and over a hundred thousand real ones is not — so timing
     * this locally would prove nothing at all. What can be proved is that the index exists.
     */
    $required = [
        'orders' => ['user_id'],
        'seller_orders' => ['seller_id'],
        'order_items' => ['order_id'],
        'product_skus' => ['product_id'],
        'ledger_lines' => ['entry_id'],
        'audit_logs' => ['action'],
        'payment_transactions' => ['payment_intent_id'],
        'cart_items' => ['cart_id'],
    ];

    $missing = [];

    foreach ($required as $table => $columns) {
        $definitions = collect(DB::select(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
            [$table],
        ))->pluck('indexdef')->implode(' ');

        foreach ($columns as $column) {
            // The column must be the first in some index: an index on (a, b) does not help
            // a query filtering only on b.
            if (preg_match('/\('.preg_quote($column, '/').'[,)]/', $definitions) !== 1) {
                $missing[] = $table.'.'.$column;
            }
        }
    }

    expect($missing)->toBe([], 'indekssiz sıcak sorgu yolları: '.implode(', ', $missing));
});

it('carries no duplicate index', function (): void {
    /*
     * A duplicate index is invisible from the outside: no query is slower, the table
     * simply pays for two index writes on every insert and holds two copies on disk.
     * Phase 21 found one that had been there for five phases.
     */
    $duplicates = DB::select(<<<'SQL'
        SELECT string_agg(indexname, ' = ') AS pair
        FROM (
            SELECT
                indexname,
                tablename,
                regexp_replace(indexdef, '^CREATE (UNIQUE )?INDEX [^ ]+ ', '') AS shape
            FROM pg_indexes
            WHERE schemaname = current_schema()
        ) AS shapes
        GROUP BY tablename, shape
        HAVING count(*) > 1
    SQL);

    $pairs = array_map(static fn (object $row): string => (string) $row->pair, $duplicates);

    expect($pairs)->toBe([], 'aynı şekle sahip indeksler: '.implode(' | ', $pairs));
});

// --- correlation ------------------------------------------------------------------

it('gives every response an id somebody can quote', function (): void {
    $response = $this->getJson('/api/health')->assertOk();

    /*
     * The audit log has carried a request_id column since Phase 1 and nothing was filling
     * it: the logger read the header and no client sends one, so the column was reliably
     * null. A field that looks like correlation and is not is worse than an absent one,
     * because somebody eventually trusts it.
     */
    $id = $response->headers->get('X-Request-Id');

    expect($id)->not->toBeNull()
        ->and(strlen((string) $id))->toBeGreaterThan(8);
});

it('honours an id the caller already assigned', function (): void {
    // A load balancer or an upstream service that has already started a trace: adopting
    // their id is what joins our logs to theirs.
    $response = $this->withHeaders(['X-Request-Id' => 'edge-7f3a91b2'])
        ->getJson('/api/health')
        ->assertOk();

    expect($response->headers->get('X-Request-Id'))->toBe('edge-7f3a91b2');
});

it('replaces an id that could not safely be logged', function (): void {
    $nasty = '<script>alert(1)</script>';

    $response = $this->withHeaders(['X-Request-Id' => $nasty])
        ->getJson('/api/health')
        ->assertOk();

    /*
     * The id reaches logs and the audit trail, both of which are read by people and
     * sometimes rendered. Replaced rather than refused: the request itself is fine, and
     * refusing it would be a strange thing to do about a header the caller need not have
     * sent at all.
     */
    expect($response->headers->get('X-Request-Id'))->not->toBe($nasty);
});

// --- the health endpoint ----------------------------------------------------------

it('answers the health probe with every dependency named', function (): void {
    $response = $this->getJson('/api/health')->assertOk();

    /*
     * A health check that returns "ok" without saying what it checked is a health check
     * that will one day be green while storage is down. Each dependency is named, so a
     * failing probe points at something.
     */
    $checks = collect($response->json('checks') ?? [])->keys();

    $missing = array_values(array_diff(
        ['database', 'cache', 'queue', 'storage', 'migrations'],
        $checks->all(),
    ));

    expect($missing)->toBe([], 'sağlık kontrolünde eksik bağımlılıklar: '.implode(', ', $missing));
});

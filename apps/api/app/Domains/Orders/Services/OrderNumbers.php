<?php

declare(strict_types=1);

namespace App\Domains\Orders\Services;

use Illuminate\Support\Facades\DB;

/**
 * The number a customer reads out on the phone.
 *
 * A PostgreSQL sequence rather than a count or a random string. A count is a race — two
 * orders in the same second get the same number — and a random string is unreadable, which
 * matters because the entire purpose of this value is being said aloud and typed back.
 *
 * The sequence is consumed outside any rollback the caller might do, which means gaps are
 * possible. That is deliberate: gaps are harmless, and the alternative — a gapless
 * sequence — needs a lock held for the length of the whole order transaction and turns
 * checkout into a queue of one.
 */
final class OrderNumbers
{
    /** RC-2026-001234 — the year, so a support desk can date it at a glance. */
    public function next(): string
    {
        $value = (int) DB::selectOne("SELECT nextval('order_number_seq') AS value")->value;

        return sprintf('RC-%s-%06d', now()->format('Y'), $value);
    }

    /**
     * The seller's own number, derived from the master.
     *
     * Derived rather than independent so that a seller reading out `RC-2026-001234-2` and
     * a customer reading out `RC-2026-001234` are obviously talking about the same order —
     * which is what support needs and what two unrelated sequences would destroy.
     */
    public function forSeller(string $orderNumber, int $sequence): string
    {
        return sprintf('%s-%d', $orderNumber, $sequence);
    }
}

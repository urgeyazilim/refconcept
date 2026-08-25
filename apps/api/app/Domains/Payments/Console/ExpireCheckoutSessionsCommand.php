<?php

declare(strict_types=1);

namespace App\Domains\Payments\Console;

use App\Domains\Payments\Services\CheckoutService;
use Illuminate\Console\Command;

/**
 * Closes checkouts whose time is up and gives the stock back.
 *
 * More than housekeeping. A customer may only have one live session per purpose — a
 * partial unique index says so, because two open checkouts would mean two stock holds for
 * one basket — so a session abandoned at the payment step does not merely sit there: it
 * locks that customer out of starting a new checkout at all until something clears it.
 * This is that something.
 *
 * Sessions whose payment is genuinely in flight are left alone. Somebody standing at
 * their bank's 3DS page has left our clock behind, and coming back to "your payment
 * expired" while the bank believes it succeeded is worse than a session that lives a few
 * minutes past its advertised life.
 */
final class ExpireCheckoutSessionsCommand extends Command
{
    protected $signature = 'refconcept:expire-checkouts';

    protected $description = 'Süresi dolmuş ödeme oturumlarını kapatır ve stoğu serbest bırakır.';

    public function handle(CheckoutService $checkout): int
    {
        $closed = $checkout->expireOverdue();

        $this->info($closed === 0
            ? 'Süresi dolmuş ödeme oturumu yok.'
            : sprintf('%d ödeme oturumu kapatıldı.', $closed));

        return self::SUCCESS;
    }
}

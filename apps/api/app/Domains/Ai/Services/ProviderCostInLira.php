<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Administration\Services\PlatformSettings;

/**
 * Turns what a provider charged into what it cost us, in lira.
 *
 * The platform reports in lira and nothing a person sees is in another currency. AI
 * providers are the one thing that does not cooperate: Google publishes its price list in
 * dollars per million tokens, so a cost arrives quoted in USD.
 *
 * The wrong fix is to relabel it. Storing dollars and printing a lira sign shows an operator
 * a number that is wrong by whatever the rate happens to be, and it is wrong silently —
 * nothing in the system would ever disagree with it.
 *
 * So the conversion happens once, here, at the moment the usage row is written, and what is
 * stored is lira. A cost recorded today does not change tomorrow because the market moved:
 * the figure is what the spend was worth when it happened, which is what a monthly total is
 * supposed to mean.
 *
 * The rate is configured rather than fetched. A live feed would make every historical figure
 * depend on a third party being up, and an AI spend report is not a trading screen.
 */
final class ProviderCostInLira
{
    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * @param  int  $micros  cost in millionths of a unit of `$currency`
     * @return int cost in millionths of a lira
     */
    public function convert(int $micros, ?string $currency): int
    {
        $currency = mb_strtoupper($currency ?? 'TRY');

        if ($micros === 0 || $currency === 'TRY') {
            return $micros;
        }

        $rate = $this->rateFor($currency);

        if ($rate <= 0.0) {
            /*
             * An unusable rate must not silently zero the cost.
             *
             * A spend report reading zero looks like a quiet month rather than like a
             * broken conversion, and nobody investigates a quiet month. Returning the
             * unconverted figure is wrong by a factor; returning zero is wrong in a way
             * that hides itself.
             */
            return $micros;
        }

        return (int) round($micros * $rate);
    }

    /** The currency the stored figure is in, which is always the platform's own. */
    public function currency(): string
    {
        return (string) config('refconcept.money.default_currency', 'TRY');
    }

    private function rateFor(string $currency): float
    {
        // Only the one that actually occurs. A general table of rates would be a table
        // somebody has to keep, for currencies no provider quotes us in.
        if ($currency !== 'USD') {
            return 0.0;
        }

        $configured = (float) config('refconcept.fx.usd_try', 0.0);

        // The operator's value wins, so a rate that has drifted is fixed from the settings
        // screen rather than from a deploy.
        $setting = $this->settings->string('finance.usd_try_rate', '');

        return $setting !== '' && is_numeric($setting) ? (float) $setting : $configured;
    }
}

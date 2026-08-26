<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\FeatureFlag;
use Illuminate\Support\Facades\Cache;

/**
 * Asks whether a feature is on, for this person.
 *
 * One place, because the interesting decision is what happens when the answer cannot be
 * found: **a missing flag is on**. A feature that switched itself off because somebody
 * forgot to seed a row would be an outage caused by the safety mechanism, which is the
 * worst way to have one. Turning something off is a decision, and a decision has a row.
 *
 * Cached for a minute like the settings, and cleared on write, so a flag flipped during
 * an incident takes effect on the next click rather than on the next minute.
 *
 * What is cached is two scalars, never the model. A cache is a different process from the
 * one that filled it, and an Eloquent object put through a shared store comes back as
 * whatever that process can reconstruct — which, the first time it cannot, is an
 * `__PHP_Incomplete_Class` and a fatal error inside a feature check. A flag lookup must
 * never be able to take down the feature it guards.
 */
final class Features
{
    private const TTL_SECONDS = 60;

    private const PREFIX = 'feature-flag:';

    /** A key that is not in the table at all, distinguished from one that is off. */
    private const ABSENT = ['absent' => true];

    public function enabled(string $key, ?string $userId = null): bool
    {
        $state = $this->state($key);

        if ($state === self::ABSENT) {
            return true;
        }

        return $this->rolloutIncludes($key, $state, $userId);
    }

    public function disabled(string $key, ?string $userId = null): bool
    {
        return ! $this->enabled($key, $userId);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX.$key);
    }

    /**
     * The same bucketing as {@see FeatureFlag::isOnFor()}, over cached scalars.
     *
     * @param  array{absent?: bool, enabled?: bool, rollout?: int}  $state
     */
    private function rolloutIncludes(string $key, array $state, ?string $userId): bool
    {
        if (($state['enabled'] ?? false) !== true) {
            return false;
        }

        $rollout = (int) ($state['rollout'] ?? 100);

        if ($rollout >= 100) {
            return true;
        }

        if ($rollout <= 0 || $userId === null) {
            return false;
        }

        // Stable in the flag key and the user id, so somebody who has the feature keeps it
        // and two flags at 50% do not select the same half.
        $bucket = hexdec(substr(hash('sha256', $key.':'.$userId), 0, 8)) % 100;

        return $bucket < $rollout;
    }

    /**
     * @return array{absent?: bool, enabled?: bool, rollout?: int}
     */
    private function state(string $key): array
    {
        /** @var array{absent?: bool, enabled?: bool, rollout?: int} $state */
        $state = Cache::remember(self::PREFIX.$key, self::TTL_SECONDS, static function () use ($key): array {
            $flag = FeatureFlag::query()->where('key', $key)->first();

            if (! $flag instanceof FeatureFlag) {
                return self::ABSENT;
            }

            return ['enabled' => $flag->is_enabled, 'rollout' => $flag->rollout_percentage];
        });

        return $state;
    }
}

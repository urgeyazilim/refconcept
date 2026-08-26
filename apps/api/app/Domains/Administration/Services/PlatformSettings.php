<?php

declare(strict_types=1);

namespace App\Domains\Administration\Services;

use App\Domains\Administration\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the values an operator is allowed to change without a deploy.
 *
 * The point of this class is that the settings screen is not decorative. A screen that
 * writes rows nothing reads is worse than no screen: it tells whoever used it that they
 * changed something, and they will act on that belief.
 *
 * The environment is the floor, not the record. `config()` supplies the value a fresh
 * stack runs on and the value the platform falls back to if the row is missing; a stored
 * row overrides it. Two sources of truth would be a bug, so the order is always the same
 * and stated once, here.
 *
 * Cached for a minute rather than for the request. Longer would mean an operator changes
 * a hold period and watches nothing happen; shorter would mean a query on every order.
 * Writes clear the key, so the delay only ever applies to a change made elsewhere.
 */
final class PlatformSettings
{
    private const TTL_SECONDS = 60;

    private const PREFIX = 'platform-setting:';

    public function integer(string $key, int $default): int
    {
        $value = $this->raw($key);

        // A row that is present but not a number is treated as absent. An operator who
        // managed to store "iki" should get the configured default rather than a zero,
        // because a hold period of zero pays sellers before the return window closes.
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default): bool
    {
        $value = $this->raw($key);

        if ($value === null) {
            return $default;
        }

        return in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function string(string $key, string $default): string
    {
        $value = $this->raw($key);

        return $value === null || $value === '' ? $default : $value;
    }

    /** Called after a write, so the operator sees their own change immediately. */
    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX.$key);
    }

    private function raw(string $key): ?string
    {
        /** @var string|null $value */
        $value = Cache::remember(
            self::PREFIX.$key,
            self::TTL_SECONDS,
            static fn (): ?string => SystemSetting::query()->where('key', $key)->value('value'),
        );

        return $value;
    }
}

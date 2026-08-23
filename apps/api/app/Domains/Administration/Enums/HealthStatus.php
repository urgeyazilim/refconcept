<?php

declare(strict_types=1);

namespace App\Domains\Administration\Enums;

/**
 * Result of a single dependency probe.
 *
 * `degraded` means the platform still serves traffic but something is impaired
 * (e.g. mail transport down); `down` means the dependency is unusable.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    /** Ordering used when collapsing many probes into one overall status. */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }

    public static function worst(self ...$statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}

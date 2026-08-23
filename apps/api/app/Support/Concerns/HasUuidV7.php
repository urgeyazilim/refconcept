<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * UUIDv7 primary keys (05_ARCHITECTURE_AND_CODE_RULES.md).
 *
 * v7 is time-ordered, so inserts stay append-friendly for the index and rows sort
 * chronologically by primary key — which v4 does not, and which matters for the
 * ledger, order and audit tables this project is built around.
 */
trait HasUuidV7
{
    public static function bootHasUuidV7(): void
    {
        static::creating(function (self $model): void {
            $key = $model->getKeyName();

            if (! $model->getAttribute($key)) {
                $model->setAttribute($key, (string) Str::uuid7());
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}

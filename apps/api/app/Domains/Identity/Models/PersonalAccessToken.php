<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasUuidV7;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token model, adjusted for this schema: UUID primary keys, timezone-aware
 * timestamps, and the request context that issued the token.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuidV7;

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'created_ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One authenticated device lifetime.
 *
 * Kept separately from `personal_access_tokens` so a user can review "where am I
 * signed in" and security review keeps a trail after expired tokens are pruned.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $token_id
 * @property string|null $device_name
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $started_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $ended_at
 * @property string|null $ended_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserSession extends Model
{
    use HasUuidV7;

    protected $table = 'user_sessions';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'token_id',
        'device_name',
        'ip_address',
        'user_agent',
        'started_at',
        'last_seen_at',
        'ended_at',
        'ended_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}

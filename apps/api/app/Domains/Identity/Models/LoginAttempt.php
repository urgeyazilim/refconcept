<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Forensic record of every authentication attempt, successful or not.
 *
 * Recorded for identifiers that do not resolve to an account as well — that is the
 * signal that distinguishes a forgotten password from credential stuffing.
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $successful
 * @property string|null $failure_reason
 * @property Carbon $created_at
 */
class LoginAttempt extends Model
{
    protected $table = 'login_attempts';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'identifier',
        'ip_address',
        'user_agent',
        'successful',
        'failure_reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

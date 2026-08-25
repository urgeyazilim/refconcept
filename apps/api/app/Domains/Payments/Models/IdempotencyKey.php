<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One client-supplied key and the answer it earned.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $scope
 * @property string $key
 * @property string $request_fingerprint
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 * @property Carbon|null $locked_at
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class IdempotencyKey extends Model
{
    use HasUuidV7;

    protected $table = 'idempotency_keys';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'scope',
        'key',
        'request_fingerprint',
        'response_status',
        'response_body',
        'locked_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null && $this->response_status !== null;
    }
}

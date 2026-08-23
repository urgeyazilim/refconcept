<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use e-mail verification token.
 *
 * Only the SHA-256 hash is persisted; the plaintext exists once, in the e-mail.
 * A database leak therefore cannot be used to verify somebody else's address.
 *
 * @property string $id
 * @property string $user_id
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $requested_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class EmailVerificationToken extends Model
{
    use HasUuidV7;

    protected $table = 'email_verification_tokens';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'email',
        'token_hash',
        'expires_at',
        'consumed_at',
        'requested_ip',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}

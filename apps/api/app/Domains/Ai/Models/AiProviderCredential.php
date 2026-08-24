<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An API key for a model provider.
 *
 * Encrypted at rest by Laravel's cast, which means a database dump on its own is not a
 * usable set of keys — the application key is needed too, and that lives somewhere
 * else. Never serialised: an admin screen shows the hint and nothing more.
 *
 * @property string $id
 * @property string $provider_id
 * @property string $label
 * @property string $secret_encrypted
 * @property string $secret_hint
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiProviderCredential extends Model
{
    use HasUuidV7;

    protected $table = 'ai_provider_credentials';

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** The key never appears in a response, however somebody serialises this. */
    protected $hidden = ['secret_encrypted'];

    /** @var list<string> */
    protected $fillable = [
        'provider_id',
        'label',
        'secret_encrypted',
        'secret_hint',
        'is_active',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted going in, decrypted coming out, unreadable in between.
            'secret_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->is_active && ! $this->hasExpired();
    }
}

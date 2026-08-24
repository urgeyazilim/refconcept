<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A machine credential for a seller's own systems.
 *
 * Split in two on purpose. `key_id` is public — it appears in logs and on screen, and
 * identifies the credential so a request can be attributed and rate-limited *before*
 * the expensive hash comparison. The secret is hashed and shown exactly once, at
 * creation, on the same reasoning as a password: a database that leaks must not hand
 * over live keys.
 *
 * Scopes are stored rather than implied by the seller's role. An ERP that only needs
 * to push stock levels should not hold a credential that can also change prices.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $key_id
 * @property string $secret_hash
 * @property string $secret_hint
 * @property array<int, string> $scopes
 * @property int $rate_limit_per_minute
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiCredential extends Model
{
    use HasUuidV7;

    /** Everything a partner integration is allowed to ask for. */
    public const SCOPES = [
        'catalog:read',
        'products:read',
        'products:write',
        'prices:read',
        'prices:write',
        'stock:read',
        'stock:write',
        'orders:read',
    ];

    protected $table = 'api_credentials';

    /**
     * The hash is never serialised, for the same reason a password hash is not: a
     * response that contains it hands an attacker something to work offline on.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_hash'];

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'name',
        'key_id',
        'secret_hash',
        'secret_hint',
        'scopes',
        'rate_limit_per_minute',
        'expires_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired();
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}

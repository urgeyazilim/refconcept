<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use App\Domains\Organizations\Models\Organization;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One role grant. `organization_id` is null for platform roles and set for
 * organization-scoped ones; grants can expire, which is how temporary elevated
 * access is handed out without anyone having to remember to take it back.
 *
 * @property string $id
 * @property string $user_id
 * @property string $role_id
 * @property string|null $organization_id
 * @property string|null $granted_by
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserRole extends Model
{
    use HasUuidV7;

    protected $table = 'user_roles';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'role_id',
        'organization_id',
        'granted_by',
        'granted_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}

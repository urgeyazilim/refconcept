<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\MembershipStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership of a user in an organization.
 *
 * Membership answers "which tenant"; a role grant answers "with what authority".
 * Both are required — being a member with no role grants read access to nothing.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property MembershipStatus $status
 * @property string|null $invited_by
 * @property Carbon|null $invited_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationUser extends Model
{
    use HasUuidV7;

    protected $table = 'organization_users';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'invited_by',
        'invited_at',
        'joined_at',
        'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active->value);
    }
}

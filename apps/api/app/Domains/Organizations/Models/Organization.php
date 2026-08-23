<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\OrganizationStatus;
use App\Domains\Organizations\Enums\OrganizationType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenant boundary.
 *
 * Everything a seller owns hangs off an organization, and every seller-facing query
 * is scoped by it. "Seller A cannot read seller B" is therefore one rule enforced in
 * policies against this relationship, rather than a condition each query must
 * remember to add.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property OrganizationStatus $status
 * @property string|null $owner_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Organization extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'organizations';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'owner_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'status' => OrganizationStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<OrganizationUser, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot(['status', 'joined_at', 'removed_at'])
            ->wherePivot('status', 'active')
            ->withTimestamps();
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }
}

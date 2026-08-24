<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserAddress;
use App\Domains\Projects\Enums\ProjectRole;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Enums\ProjectType;
use App\Support\Casts\MoneyCast;
use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Money;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer's home, or the part of it they are working on.
 *
 * Ownership has exactly one answer — `user_id` — and everybody else is a row in
 * {@see ProjectMember}. The owner is deliberately *not* a member row: two sources for
 * "who owns this" is how a project ends up ownerless when somebody removes the wrong
 * membership.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property ProjectType $project_type
 * @property ProjectStatus $status
 * @property string $currency
 * @property Money|null $budget_minor
 * @property string|null $address_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'projects';

    /** @var array<string, mixed> */
    protected $attributes = [
        'project_type' => 'home',
        'status' => 'active',
        'currency' => 'TRY',
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'project_type',
        'currency',
        'budget_minor',
        'address_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'budget_minor' => MoneyCast::class.':currency',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<UserAddress, $this> */
    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('position');
    }

    /** @return HasManyThrough<Design, Room, $this> */
    public function designs(): HasManyThrough
    {
        return $this->hasManyThrough(Design::class, Room::class);
    }

    /** @return HasMany<ProjectStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ProjectStatusHistory::class)->orderByDesc('changed_at');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->getKey();
    }

    /**
     * The live membership for this user, if any.
     *
     * Returns null for the owner: they are not a member, and treating them as one
     * would make their permissions depend on a row that might not exist.
     */
    public function membershipFor(User $user): ?ProjectMember
    {
        $this->loadMissing('members');

        return $this->members
            ->firstWhere(fn (ProjectMember $member): bool => $member->user_id === $user->getKey()
                && $member->isActive());
    }

    /** Whether this user may change anything: the owner, or an active editor. */
    public function isEditableBy(User $user): bool
    {
        if (! $this->status->isEditable()) {
            return false;
        }

        if ($this->isOwnedBy($user)) {
            return true;
        }

        return $this->membershipFor($user)?->role === ProjectRole::Editor;
    }

    /** @param  Builder<$this>  $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $inner) use ($user): void {
            $inner->where('user_id', $user->getKey())
                ->orWhereHas('members', function (Builder $member) use ($user): void {
                    $member->where('user_id', $user->getKey())->where('status', 'active');
                });
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\ProjectRole;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody the owner let into a project.
 *
 * Invited by e-mail rather than by user id, because the person you want to show your
 * living room to usually does not have an account yet. `user_id` fills in when they
 * accept, and until then the row is a promise rather than an access grant — which is
 * why every permission check reads {@see isActive()} rather than merely the row's
 * existence.
 *
 * The invitation token is hashed. It is a bearer secret for photographs of somebody's
 * home, and a leaked mail archive should not be a way in.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $user_id
 * @property string $invited_email
 * @property ProjectRole $role
 * @property string $status
 * @property string|null $invitation_token_hash
 * @property Carbon|null $invitation_expires_at
 * @property string|null $invited_by
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectMember extends Model
{
    use HasUuidV7;

    protected $table = 'project_members';

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'viewer',
        'status' => 'invited',
    ];

    /** Never serialised, for the same reason a password hash is not. */
    protected $hidden = ['invitation_token_hash'];

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'user_id',
        'invited_email',
        'role',
        'invitation_token_hash',
        'invitation_expires_at',
        'invited_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'invitation_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Whether this row currently grants anything at all. */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->user_id !== null && $this->revoked_at === null;
    }

    public function invitationHasExpired(): bool
    {
        return $this->status === 'invited'
            && $this->invitation_expires_at !== null
            && $this->invitation_expires_at->isPast();
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active')->whereNotNull('user_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Organization;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One immutable audit entry.
 *
 * A database trigger rejects UPDATE and DELETE; these overrides make the same rule
 * fail fast in PHP with a message that says why, instead of surfacing as a raw
 * Postgres exception from somewhere deep in Eloquent.
 *
 * @property string $id
 * @property string|null $actor_id
 * @property string $actor_type
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $organization_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $context
 * @property string|null $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUuidV7;

    protected $table = 'audit_logs';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_label',
        'action',
        'auditable_type',
        'auditable_id',
        'organization_id',
        'changes',
        'context',
        'reason',
        'ip_address',
        'user_agent',
        'request_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new RuntimeException(
            'Audit log entries are immutable. Record a corrective entry instead of editing history.'
        );
    }

    public function delete(): bool
    {
        throw new RuntimeException(
            'Audit log entries are immutable and cannot be deleted.'
        );
    }
}

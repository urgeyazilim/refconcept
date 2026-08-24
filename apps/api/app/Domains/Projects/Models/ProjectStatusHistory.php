<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every change to a project's status.
 *
 * Modest but worth keeping: a project that was completed in March and reopened in
 * September is a different story from one that has been open all year, and only the
 * history can tell them apart.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $changed_by
 * @property Carbon $changed_at
 */
class ProjectStatusHistory extends Model
{
    use HasUuidV7;

    protected $table = 'project_status_history';

    /** A row that only records a moment has no separate created/updated pair. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'from_status',
        'to_status',
        'changed_by',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

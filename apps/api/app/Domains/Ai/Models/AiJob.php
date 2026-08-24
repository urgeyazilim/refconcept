<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Enums\AiFailureKind;
use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Enums\AiTask;
use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One unit of AI work.
 *
 * The subject is polymorphic because a job renders a design version, tags a product or
 * rewrites a search query, and a foreign key per possibility would be a column per
 * feature. It is nullable because some tasks — a support answer, a query rewrite —
 * have no row they belong to at all.
 *
 * Cost and latency are denormalised from `ai_usage` so a list of jobs does not need a
 * join. They are written by the gateway inside the same transaction as the usage rows
 * they summarise, so they cannot drift.
 *
 * @property string $id
 * @property AiTask $task
 * @property AiJobStatus $status
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $user_id
 * @property string|null $route_id
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $output
 * @property int $attempts
 * @property int $credit_cost
 * @property int $total_cost_micros
 * @property int $total_latency_ms
 * @property AiFailureKind|null $failure_kind
 * @property string|null $failure_reason
 * @property string|null $idempotency_key
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiJob extends Model
{
    use HasUuidV7;

    protected $table = 'ai_jobs';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'attempts' => 0,
        'credit_cost' => 0,
        'total_cost_micros' => 0,
        'total_latency_ms' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'task',
        'subject_type',
        'subject_id',
        'user_id',
        'route_id',
        'input',
        'credit_cost',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiJobStatus::class,
            'failure_kind' => AiFailureKind::class,
            'input' => 'array',
            'output' => 'array',
            'attempts' => 'integer',
            'credit_cost' => 'integer',
            'total_cost_micros' => 'integer',
            'total_latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AiTaskRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(AiTaskRoute::class, 'route_id');
    }

    /** @return HasMany<AiRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'job_id')->orderBy('attempt');
    }

    /** @return HasMany<AiUsage, $this> */
    public function usage(): HasMany
    {
        return $this->hasMany(AiUsage::class, 'job_id');
    }

    /** @return HasMany<AiFailure, $this> */
    public function failures(): HasMany
    {
        return $this->hasMany(AiFailure::class, 'job_id')->orderBy('attempt');
    }

    /** How long the whole job took, including time spent queued. */
    public function wallClockMs(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMilliseconds($this->finished_at);
    }

    /** Cost as a decimal string, for a screen. Never used in arithmetic. */
    public function costFormatted(string $currency = 'USD'): string
    {
        return number_format($this->total_cost_micros / 1_000_000, 4).' '.$currency;
    }

    /** @param  Builder<$this>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', [AiJobStatus::Queued->value, AiJobStatus::Running->value]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSubject(Builder $query, string $type, string $id): void
    {
        $query->where('subject_type', $type)->where('subject_id', $id);
    }
}

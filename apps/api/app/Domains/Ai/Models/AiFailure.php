<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Enums\AiFailureKind;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why one attempt did not produce an answer.
 *
 * The kind is stored alongside the message because they answer different questions.
 * The message is for a person reading one failure; the kind is what a dashboard groups
 * by, and "timeouts tripled at 14:00" is a sentence only the kind can produce.
 *
 * `was_retryable` records the decision that was actually made rather than recomputing
 * it later. The classification can change with a code release, and a report that
 * silently reinterprets last month's failures under this month's rules is a report
 * that lies about what happened.
 *
 * @property string $id
 * @property string $job_id
 * @property string|null $request_id
 * @property AiFailureKind $kind
 * @property string $message
 * @property bool $was_retryable
 * @property int $attempt
 * @property Carbon $created_at
 */
class AiFailure extends Model
{
    use HasUuidV7;

    protected $table = 'ai_failures';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'job_id',
        'request_id',
        'kind',
        'message',
        'was_retryable',
        'attempt',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiFailureKind::class,
            'was_retryable' => 'boolean',
            'attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'job_id');
    }

    /** @return BelongsTo<AiRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeOfKind(Builder $query, AiFailureKind $kind): void
    {
        $query->where('kind', $kind->value);
    }
}

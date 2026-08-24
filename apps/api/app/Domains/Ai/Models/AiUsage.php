<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Enums\AiTask;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one attempt consumed, and what it cost.
 *
 * Written for failed attempts too. A provider that read the input and then refused
 * still charges for the input, and a cost report that ignores failures understates the
 * bill by exactly the amount somebody is about to be surprised by.
 *
 * `cost_micros` is computed here from the rate table rather than taken from the
 * provider's response, so a provider cannot misreport what RefConcept believes it
 * spent.
 *
 * @property string $id
 * @property string $request_id
 * @property string $job_id
 * @property string|null $model_id
 * @property AiTask $task
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $image_count
 * @property int $cost_micros
 * @property string $currency
 * @property int $credits_charged
 * @property int $latency_ms
 * @property Carbon $created_at
 */
class AiUsage extends Model
{
    use HasUuidV7;

    protected $table = 'ai_usage';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'request_id',
        'job_id',
        'model_id',
        'task',
        'input_tokens',
        'output_tokens',
        'image_count',
        'cost_micros',
        'currency',
        'credits_charged',
        'latency_ms',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'image_count' => 'integer',
            'cost_micros' => 'integer',
            'credits_charged' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class, 'request_id');
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'job_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeSince(Builder $query, Carbon $from): void
    {
        $query->where('created_at', '>=', $from);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One attempt against one provider.
 *
 * A job can have several — a retry, then a fallback to a different provider — and
 * keeping them apart is what makes "the primary is timing out but the fallback is
 * fine" a visible fact rather than a vague sense that things are slow.
 *
 * The rendered prompt is stored so that "why did it answer that" is answerable months
 * later against the exact text used. A customer's room photograph is *not*: only a
 * reference to it, because this table is read by staff and the photograph is not
 * theirs to look at.
 *
 * @property string $id
 * @property string $job_id
 * @property int $attempt
 * @property string|null $provider_id
 * @property string|null $model_id
 * @property string|null $prompt_version_id
 * @property bool $is_fallback
 * @property string|null $rendered_prompt
 * @property string $status
 * @property int|null $http_status
 * @property int $latency_ms
 * @property Carbon $created_at
 */
class AiRequest extends Model
{
    use HasUuidV7;

    protected $table = 'ai_requests';

    /** A row recording a moment has no updated_at worth keeping. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'job_id',
        'attempt',
        'provider_id',
        'model_id',
        'prompt_version_id',
        'is_fallback',
        'rendered_prompt',
        'status',
        'http_status',
        'latency_ms',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'is_fallback' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'job_id');
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /** @return BelongsTo<PromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }

    /** @return HasOne<AiUsage, $this> */
    public function usage(): HasOne
    {
        return $this->hasOne(AiUsage::class, 'request_id');
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}

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
 * Which model serves which task, and under what conditions.
 *
 * The row the whole domain exists for. Everything about how a task behaves —
 * provider, model, prompt version, timeout, retries, cost ceiling, concurrency, and
 * whether it runs at all — lives here so that changing any of it is configuration
 * rather than a deploy. Nothing in the application chooses a model name.
 *
 * @property string $id
 * @property AiTask $task
 * @property string $primary_model_id
 * @property string|null $fallback_model_id
 * @property string|null $prompt_version_id
 * @property int $timeout_seconds
 * @property int $max_attempts
 * @property int $credit_cost
 * @property int $max_cost_micros
 * @property int $max_concurrency
 * @property bool $is_active
 * @property bool $is_paused
 * @property string|null $pause_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiTaskRoute extends Model
{
    use HasUuidV7;

    protected $table = 'ai_task_routes';

    /** @var array<string, mixed> */
    protected $attributes = [
        'timeout_seconds' => 60,
        'max_attempts' => 3,
        'credit_cost' => 1,
        'max_cost_micros' => 500_000,
        'max_concurrency' => 10,
        'is_active' => true,
        'is_paused' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'task',
        'primary_model_id',
        'fallback_model_id',
        'prompt_version_id',
        'timeout_seconds',
        'max_attempts',
        'credit_cost',
        'max_cost_micros',
        'max_concurrency',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'timeout_seconds' => 'integer',
            'max_attempts' => 'integer',
            'credit_cost' => 'integer',
            'max_cost_micros' => 'integer',
            'max_concurrency' => 'integer',
            'is_active' => 'boolean',
            'is_paused' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiModel, $this> */
    public function primaryModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'primary_model_id');
    }

    /** @return BelongsTo<AiModel, $this> */
    public function fallbackModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'fallback_model_id');
    }

    /** @return BelongsTo<PromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }

    public function isUsable(): bool
    {
        return $this->is_active && ! $this->is_paused;
    }

    /**
     * The models to try, in order.
     *
     * A deprecated or deactivated model is skipped rather than attempted: pointing a
     * route at a model a provider has retired produces an error that reads like an
     * outage, and the operator who deprecated it is not the one who will be paged.
     *
     * @return array<int, AiModel>
     */
    public function candidateModels(): array
    {
        $this->loadMissing(['primaryModel.provider', 'fallbackModel.provider']);

        return array_values(array_filter(
            [$this->primaryModel, $this->fallbackModel],
            static fn (?AiModel $model): bool => $model !== null && $model->isUsable(),
        ));
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}

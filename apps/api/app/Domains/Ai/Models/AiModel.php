<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Enums\AiModality;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One model a provider offers.
 *
 * `code` is the provider's own identifier, passed through unchanged. Nothing in the
 * application ever writes a model name in a string literal — that is the entire point
 * of this table, and it is what lets an operator move a task onto a cheaper model
 * without a deploy.
 *
 * @property string $id
 * @property string $provider_id
 * @property string $code
 * @property string $name
 * @property AiModality $modality
 * @property int|null $context_tokens
 * @property int|null $max_output_tokens
 * @property bool $supports_structured_output
 * @property bool $supports_image_input
 * @property bool $is_active
 * @property Carbon|null $deprecated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiModel extends Model
{
    use HasUuidV7;

    protected $table = 'ai_models';

    /** @var array<string, mixed> */
    protected $attributes = [
        'supports_structured_output' => false,
        'supports_image_input' => false,
        'is_active' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'provider_id',
        'code',
        'name',
        'modality',
        'context_tokens',
        'max_output_tokens',
        'supports_structured_output',
        'supports_image_input',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modality' => AiModality::class,
            'context_tokens' => 'integer',
            'max_output_tokens' => 'integer',
            'supports_structured_output' => 'boolean',
            'supports_image_input' => 'boolean',
            'is_active' => 'boolean',
            'deprecated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /** @return HasMany<AiCostRate, $this> */
    public function costRates(): HasMany
    {
        return $this->hasMany(AiCostRate::class, 'model_id')->orderByDesc('effective_from');
    }

    /**
     * The rate in force at a moment.
     *
     * By time rather than "the latest", because a job run in March has to keep
     * reporting March's price however many times rates have changed since.
     */
    public function rateAt(?Carbon $at = null): ?AiCostRate
    {
        $at ??= now();

        $this->loadMissing('costRates');

        return $this->costRates->first(
            fn (AiCostRate $rate): bool => $rate->coversInstant($at),
        );
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->deprecated_at === null;
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('deprecated_at');
    }
}

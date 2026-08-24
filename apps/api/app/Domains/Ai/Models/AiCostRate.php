<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a model cost, over a period.
 *
 * Everything here is in **micros** — millionths of one currency unit. A thousand
 * tokens can cost a fraction of a cent, and storing that in minor units would round
 * every rate to zero and every cost report with it.
 *
 * This is the one place in RefConcept where money is not in minor units, and the
 * exception is deliberate: these are provider costs measured in fractions of a cent,
 * not amounts anybody is ever charged. What a *customer* pays is credits, and credits
 * are integers.
 *
 * @property string $id
 * @property string $model_id
 * @property string $currency
 * @property int $input_micros_per_million_tokens
 * @property int $output_micros_per_million_tokens
 * @property int $micros_per_image
 * @property int $micros_per_request
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
class AiCostRate extends Model
{
    use HasUuidV7;

    protected $table = 'ai_cost_rates';

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'USD'];

    /** @var list<string> */
    protected $fillable = [
        'model_id',
        'currency',
        'input_micros_per_million_tokens',
        'output_micros_per_million_tokens',
        'micros_per_image',
        'micros_per_request',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_micros_per_million_tokens' => 'integer',
            'output_micros_per_million_tokens' => 'integer',
            'micros_per_image' => 'integer',
            'micros_per_request' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<AiModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    public function coversInstant(Carbon $at): bool
    {
        if ($at->lt($this->effective_from)) {
            return false;
        }

        return $this->effective_to === null || $at->lt($this->effective_to);
    }

    /**
     * What one call at these volumes costs, in micros.
     *
     * Integer arithmetic throughout, and `intdiv` rather than `/`: the whole reason for
     * the micros unit is to keep a float away from a figure that gets summed into a
     * monthly bill. Division truncates, which under-reports by less than one micro per
     * call — an error small enough to accept and consistent enough to reason about.
     */
    public function costFor(int $inputTokens, int $outputTokens, int $images): int
    {
        return $this->micros_per_request
            + intdiv($inputTokens * $this->input_micros_per_million_tokens, 1_000_000)
            + intdiv($outputTokens * $this->output_micros_per_million_tokens, 1_000_000)
            + ($images * $this->micros_per_image);
    }
}

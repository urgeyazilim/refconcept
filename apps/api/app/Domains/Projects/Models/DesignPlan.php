<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Ai\Models\AiJob;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What goes where, decided before anything is drawn.
 *
 * The plan is the part of a design that a shopping list can be built from. "A sofa up to
 * 2200mm against the south wall, in oak and cream" is a product search; the image is not,
 * and Phase 9 matches against this rather than trying to read the picture back.
 *
 * Immutable once written — by a database trigger — because it is the row that answers
 * "why is there a sideboard there", and a plan that changed after its image was produced
 * would make the image unexplainable.
 *
 * @property string $id
 * @property string $design_version_id
 * @property string|null $ai_job_id
 * @property string|null $room_analysis_id
 * @property string|null $style
 * @property array<int, string>|null $palette
 * @property array<string, mixed>|null $composition
 * @property array<int, mixed> $placements
 * @property string|null $notes
 * @property array<int, mixed>|null $rejected
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignPlan extends Model
{
    use HasUuidV7;

    protected $table = 'design_plans';

    /** @var list<string> */
    protected $fillable = [
        'design_version_id',
        'ai_job_id',
        'room_analysis_id',
        'style',
        'palette',
        'composition',
        'placements',
        'notes',
        'rejected',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'palette' => 'array',
            'composition' => 'array',
            'placements' => 'array',
            'rejected' => 'array',
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    /** @return BelongsTo<RoomAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(RoomAnalysis::class, 'room_analysis_id');
    }

    /**
     * The categories this plan asks for, in order.
     *
     * What Phase 9 turns into searches. Deduplicated, because a plan that names "kanepe"
     * twice wants two sofas, not two searches for the same thing.
     *
     * @return array<int, string>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->placements as $placement) {
            if (! is_array($placement)) {
                continue;
            }

            $category = $placement['category'] ?? null;

            if (is_string($category) && $category !== '') {
                $categories[] = $category;
            }
        }

        return array_values(array_unique($categories));
    }

    /** Whether the room forced anything out of the plan. */
    public function hasRejections(): bool
    {
        return ($this->rejected ?? []) !== [];
    }
}

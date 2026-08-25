<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Ai\Models\AiJob;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a model saw in one photograph of a room.
 *
 * Keyed on the photograph, not on the design. The room does not change when somebody
 * tries a second style, so re-reading it would be a second charge for an answer we
 * already have — and a customer who takes a better photograph gets a new analysis while
 * the old one stays attached to the picture it actually described.
 *
 * `payload` holds the whole validated answer and the columns lift out the parts something
 * reads on every generation. Keeping both is deliberate: the columns are for speed, and
 * the payload is what makes the analysis still useful when a later phase wants a field
 * nobody thought to extract.
 *
 * @property string $id
 * @property string $room_id
 * @property string $media_id
 * @property string|null $ai_job_id
 * @property string|null $detected_room_type
 * @property int|null $confidence_bps
 * @property string|null $measurement_quality
 * @property array<string, mixed> $payload
 * @property array<int, mixed>|null $fixed_elements
 * @property array<string, mixed>|null $surfaces
 * @property array<int, mixed>|null $warnings
 * @property bool $is_current
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoomAnalysis extends Model
{
    use HasUuidV7;

    protected $table = 'room_analyses';

    /** @var array<string, mixed> */
    protected $attributes = ['is_current' => true];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'media_id',
        'ai_job_id',
        'detected_room_type',
        'confidence_bps',
        'measurement_quality',
        'payload',
        'fixed_elements',
        'surfaces',
        'warnings',
        'is_current',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_bps' => 'integer',
            'payload' => 'array',
            'fixed_elements' => 'array',
            'surfaces' => 'array',
            'warnings' => 'array',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<RoomMedia, $this> */
    public function media(): BelongsTo
    {
        return $this->belongsTo(RoomMedia::class, 'media_id');
    }

    /** @return BelongsTo<AiJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(AiJob::class, 'ai_job_id');
    }

    /**
     * Whether this reading is confident enough to design from.
     *
     * Below sixty per cent the planner is working from a guess, and a design built on a
     * guess is worse than one the customer was asked to help with — so the pipeline
     * carries on but the warning reaches the screen.
     */
    public function isConfident(): bool
    {
        return $this->confidence_bps === null || $this->confidence_bps >= 6_000;
    }

    /**
     * Things the design must not remove, in the planner's words.
     *
     * @return array<int, string>
     */
    public function preservedElements(): array
    {
        $preserved = [];

        foreach ($this->fixed_elements ?? [] as $element) {
            if (! is_array($element)) {
                continue;
            }

            // `preserve` absent means preserve. A model that forgets the flag must not
            // thereby give the renderer permission to brick up a window.
            if (($element['preserve'] ?? true) === false) {
                continue;
            }

            $type = $element['type'] ?? null;

            if (is_string($type) && $type !== '') {
                $preserved[] = $type;
            }
        }

        return array_values(array_unique($preserved));
    }

    /** @param  Builder<$this>  $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }
}

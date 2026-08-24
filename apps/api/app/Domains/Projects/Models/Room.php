<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One room in a project.
 *
 * The envelope lives here rather than in a one-to-one dimensions table: every room has
 * exactly one, and a join to fetch three integers buys nothing. What genuinely varies
 * in number is {@see RoomConstraint}.
 *
 * `room_type` uses the same vocabulary as the product catalogue, which is what makes
 * matching possible at all — a bedroom design offers bedroom furniture because the two
 * agree on what a bedroom is.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property RoomType $room_type
 * @property MeasurementQuality $measurement_quality
 * @property int|null $width_mm
 * @property int|null $length_mm
 * @property int|null $height_mm
 * @property string|null $primary_media_id
 * @property string|null $notes
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Room extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'rooms';

    /** @var array<string, mixed> */
    protected $attributes = [
        'measurement_quality' => 'unknown',
        'position' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'name',
        'room_type',
        'measurement_quality',
        'width_mm',
        'length_mm',
        'height_mm',
        'primary_media_id',
        'notes',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'room_type' => RoomType::class,
            'measurement_quality' => MeasurementQuality::class,
            'width_mm' => 'integer',
            'length_mm' => 'integer',
            'height_mm' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<RoomMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(RoomMedia::class)->orderBy('position');
    }

    /**
     * The photograph the design engine works from.
     *
     * @return BelongsTo<RoomMedia, $this>
     */
    public function primaryMedia(): BelongsTo
    {
        return $this->belongsTo(RoomMedia::class, 'primary_media_id');
    }

    /** @return HasMany<RoomConstraint, $this> */
    public function constraints(): HasMany
    {
        return $this->hasMany(RoomConstraint::class);
    }

    /** @return HasMany<Design, $this> */
    public function designs(): HasMany
    {
        return $this->hasMany(Design::class)->orderByDesc('created_at');
    }

    /** Floor area in square metres, when the room has been measured. */
    public function floorAreaM2(): ?float
    {
        if ($this->width_mm === null || $this->length_mm === null) {
            return null;
        }

        return round(($this->width_mm / 1000) * ($this->length_mm / 1000), 2);
    }

    /**
     * Whether this room can be designed yet.
     *
     * A photograph is the one hard requirement: the engine works from the room the
     * customer actually has, and there is no way to invent one. Measurements make the
     * result better but their absence is a quality problem, not a blocker.
     */
    public function isReadyForDesign(): bool
    {
        return $this->primary_media_id !== null;
    }

    /**
     * What is still missing, in the customer's words.
     *
     * @return array<int, string>
     */
    public function missingForDesign(): array
    {
        $missing = [];

        if ($this->primary_media_id === null) {
            $missing[] = 'Odanın fotoğrafı';
        }

        if ($this->width_mm === null || $this->length_mm === null) {
            // Not a blocker, but said out loud: a design placed against a guessed wall
            // is a suggestion rather than something to order furniture against.
            $missing[] = 'Oda ölçüleri (isteğe bağlı, ama sonucu belirgin şekilde iyileştirir)';
        }

        return $missing;
    }

    /** @param  Builder<$this>  $query */
    public function scopeForProject(Builder $query, string $projectId): void
    {
        $query->where('project_id', $projectId);
    }
}

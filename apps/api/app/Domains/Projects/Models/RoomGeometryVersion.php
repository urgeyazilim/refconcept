<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a room measures, and who said so.
 *
 * The estimate a model produced from a photograph and the figures a customer confirmed are
 * different kinds of claim, and this table refuses to conflate them. An estimate is a
 * proposal: nothing is built on it until somebody has read the numbers and agreed. A
 * confirmed version is authoritative, and there is exactly one of them per room — enforced
 * by a partial unique index, because two answers to "how wide is that wall" would let every
 * placement check quietly pick whichever suited it.
 *
 * Versioned rather than overwritten. A customer who corrects 4.85 m to 4.20 m has told us
 * something about the estimator, and a customer who accepts it has told us something too;
 * both disappear the moment one row is updated in place.
 *
 * @property string $id
 * @property string $room_id
 * @property int $version
 * @property string $source
 * @property int $width_mm
 * @property int $length_mm
 * @property int $height_mm
 * @property int|null $confidence_bps
 * @property string|null $analysis_id
 * @property bool $is_confirmed
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoomGeometryVersion extends Model
{
    use HasUuidV7;

    protected $table = 'room_geometry_versions';

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => 'ai',
        'is_confirmed' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'version',
        'source',
        'width_mm',
        'length_mm',
        'height_mm',
        'confidence_bps',
        'analysis_id',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'width_mm' => 'integer',
            'length_mm' => 'integer',
            'height_mm' => 'integer',
            'confidence_bps' => 'integer',
            'is_confirmed' => 'boolean',
            'confirmed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<RoomAnalysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(RoomAnalysis::class, 'analysis_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The one version anything may be built on.
     *
     * @param  Builder<RoomGeometryVersion>  $query
     * @return Builder<RoomGeometryVersion>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('is_confirmed', true);
    }

    /**
     * How sure the estimator was, as a percentage, or null for figures a person gave.
     *
     * A customer who measured their own room with a tape is not eighty-seven per cent
     * confident; they are simply right, and showing doubt beside their own number would be
     * the screen arguing with them.
     */
    public function confidencePercent(): ?int
    {
        return $this->confidence_bps === null ? null : (int) round($this->confidence_bps / 100);
    }

    /** Floor area in square metres, for a screen that wants to say "about 20 m²". */
    public function floorAreaM2(): float
    {
        return round(($this->width_mm / 1000) * ($this->length_mm / 1000), 1);
    }
}

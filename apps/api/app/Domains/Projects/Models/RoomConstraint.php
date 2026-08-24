<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Projects\Enums\ConstraintType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something in a room that furniture has to work around.
 *
 * Placed rather than merely listed: `wall` plus `offset_mm` plus `width_mm` says where
 * a window actually is, which is what decides whether a 220 cm sofa fits under it.
 * "There is a window" decides nothing.
 *
 * @property string $id
 * @property string $room_id
 * @property ConstraintType $type
 * @property string|null $label
 * @property string|null $wall
 * @property int|null $offset_mm distance from the left edge of that wall, seen from inside
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property int|null $sill_height_mm
 * @property bool $is_blocking
 * @property bool $must_stay_visible
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RoomConstraint extends Model
{
    use HasUuidV7;

    protected $table = 'room_constraints';

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_blocking' => true,
        'must_stay_visible' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'type',
        'label',
        'wall',
        'offset_mm',
        'width_mm',
        'height_mm',
        'sill_height_mm',
        'is_blocking',
        'must_stay_visible',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConstraintType::class,
            'offset_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'sill_height_mm' => 'integer',
            'is_blocking' => 'boolean',
            'must_stay_visible' => 'boolean',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Whether this is placed precisely enough for the engine to reason about.
     *
     * A window with no wall and no offset is a note to the customer, not a constraint:
     * it cannot rule any layout in or out.
     */
    public function isPlaced(): bool
    {
        return $this->wall !== null && $this->offset_mm !== null && $this->width_mm !== null;
    }

    /** A short human description, so a list of constraints reads as sentences. */
    public function describe(): string
    {
        $parts = [$this->label ?: $this->type->label()];

        if ($this->width_mm !== null) {
            $parts[] = sprintf('%d cm genişlik', (int) round($this->width_mm / 10));
        }

        if ($this->sill_height_mm !== null) {
            $parts[] = sprintf('yerden %d cm', (int) round($this->sill_height_mm / 10));
        }

        return implode(' · ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One arrangement of one room.
 *
 * Anchored to the room rather than to a design, because that is the order things happen in:
 * somebody arranges their living room and only then asks for a picture of it. A design is
 * attached when one is made, which is what makes "this render came from that layout"
 * answerable afterwards — and answerable is the point, since the render's whole job will be
 * to agree with these coordinates.
 *
 * `source` survives forever because "the engine put it there" and "I put it there" are
 * different claims about the same sofa. A customer who dislikes an arrangement is telling us
 * about one of them, and which one decides whether the complaint is about the layout engine
 * or about the catalogue.
 *
 * @property string $id
 * @property string $room_id
 * @property string|null $design_id
 * @property string $geometry_version_id
 * @property int $version
 * @property string $source
 * @property string $status
 * @property string|null $snapshot_disk
 * @property string|null $snapshot_path
 * @property Carbon|null $snapshot_taken_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DesignLayout extends Model
{
    use HasUuidV7;

    protected $table = 'design_layouts';

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => 'user',
        'status' => 'draft',
    ];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'design_id',
        'geometry_version_id',
        'version',
        'source',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            // So a snapshot older than the layout it claims to show can be spotted, rather
            // than quietly sent to a renderer as the truth.
            'snapshot_taken_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Design, $this> */
    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class);
    }

    /**
     * The measurements this arrangement was made against.
     *
     * Held explicitly rather than looked up from the room, because a layout is only valid
     * for the geometry it was planned in: a customer who corrects their room from 4.85 m to
     * 4.20 m has not moved their furniture, they have invalidated its positions, and a
     * layout that silently followed the new numbers would put a sofa through a wall.
     *
     * @return BelongsTo<RoomGeometryVersion, $this>
     */
    public function geometry(): BelongsTo
    {
        return $this->belongsTo(RoomGeometryVersion::class, 'geometry_version_id');
    }

    /** @return HasMany<DesignLayoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DesignLayoutItem::class, 'layout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether anything in this arrangement is still in a position the room refuses. */
    public function hasCollisions(): bool
    {
        return $this->items->contains(fn (DesignLayoutItem $item): bool => $item->collision_state === 'blocked');
    }
}

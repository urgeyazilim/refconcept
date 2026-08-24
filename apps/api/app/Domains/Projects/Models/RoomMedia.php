<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\ProductMedia;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A photograph of somebody's home.
 *
 * The most private object in the system. It shows what a person owns, how they live
 * and often who lives with them, and it is protected accordingly: the private disk, a
 * random object key, and no URL that works without a policy check behind it. Unlike
 * {@see ProductMedia}, this model has no `url()` method
 * at all — there is nowhere to point one.
 *
 * `checksum_sha256` lets a support conversation confirm the file being discussed is
 * the file that was uploaded, and makes an accidental duplicate obvious.
 *
 * @property string $id
 * @property string $room_id
 * @property string $type
 * @property string $disk
 * @property string $storage_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum_sha256
 * @property string|null $caption
 * @property int $position
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class RoomMedia extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'room_media';

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'photo',
        'position' => 0,
    ];

    /**
     * The object key never leaves the server.
     *
     * A storage path is the one piece of information that turns "somebody guessed a
     * bucket name" into "somebody has the photograph".
     *
     * @var list<string>
     */
    protected $hidden = ['storage_path'];

    /** @var list<string> */
    protected $fillable = [
        'room_id',
        'type',
        'disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'checksum_sha256',
        'caption',
        'position',
        'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Whether this is the photograph the design engine works from. */
    public function isPrimary(): bool
    {
        return $this->room?->primary_media_id === $this->getKey();
    }

    /** The longer edge in pixels, for deciding whether a photo is usable. */
    public function longestEdge(): ?int
    {
        if ($this->width === null || $this->height === null) {
            return null;
        }

        return max($this->width, $this->height);
    }
}

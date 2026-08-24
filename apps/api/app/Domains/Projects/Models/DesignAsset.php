<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What a design version produced.
 *
 * Separate from {@see RoomMedia} on purpose, and that separation *is* the mechanism
 * behind "the original is immutable": there is no code path that could write an AI
 * render over the customer's own photograph, because the two live in different tables
 * with different writers. A rule enforced by structure survives a refactor; a rule
 * enforced by everybody remembering does not.
 *
 * Private, like the photograph it came from. A render of somebody's living room is
 * every bit as revealing as the original.
 *
 * @property string $id
 * @property string $design_version_id
 * @property string $type
 * @property string $disk
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum_sha256
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class DesignAsset extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'design_assets';

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'render',
    ];

    /** The object key never leaves the server. */
    protected $hidden = ['storage_path'];

    /** @var list<string> */
    protected $fillable = [
        'design_version_id',
        'type',
        'disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'checksum_sha256',
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
        ];
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }
}

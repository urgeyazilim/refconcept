<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * An image or other asset attached to a product.
 *
 * Product imagery is public by design — it appears on catalogue pages and in search
 * results — so it lives on the public disk, unlike the onboarding documents in
 * Phase 2. The disk is stored per row so moving one class of asset later does not
 * strand the others.
 *
 * @property string $id
 * @property string $product_id
 * @property string $type
 * @property string $disk
 * @property string $storage_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class ProductMedia extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'product_media';

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'image',
    ];

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'type',
        'disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'alt_text',
        'position',
        'uploaded_by',
    ];

    /** The object key is infrastructure detail; clients get a URL instead. */
    protected $hidden = ['storage_path'];

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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->storage_path);
    }

    /** Position zero is the cover image, guaranteed unique by a partial index. */
    public function isCover(): bool
    {
        return $this->position === 0;
    }
}

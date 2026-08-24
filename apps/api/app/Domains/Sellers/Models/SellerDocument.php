<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Sellers\Enums\DocumentStatus;
use App\Domains\Sellers\Enums\DocumentType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An uploaded onboarding document.
 *
 * The file itself lives on the private disk; only its key is stored here. Nothing
 * ever returns `storage_path` to a client — access is a short-lived signed URL issued
 * after a policy check, because these are tax certificates and identity documents.
 *
 * @property string $id
 * @property string $application_id
 * @property DocumentType $type
 * @property string $original_name
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property DocumentStatus $status
 * @property string|null $review_note
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class SellerDocument extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'seller_documents';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /** @var list<string> */
    protected $fillable = [
        'application_id',
        'type',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'uploaded_by',
    ];

    /** The object key is infrastructure detail and a small information leak. */
    protected $hidden = [
        'storage_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'size_bytes' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A transfer receipt a customer uploaded.
 *
 * A bank's PDF or a screenshot of an app, so it can carry an account number, a balance and
 * a full name. Private disk, random key, and `storage_path` never appears in a response.
 *
 * @property string $id
 * @property string $bank_transfer_id
 * @property string|null $uploaded_by
 * @property string $original_name
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property Carbon|null $created_at
 * @property-read BankTransfer|null $transfer
 * @property-read User|null $uploader
 */
class PaymentReceipt extends Model
{
    use HasUuidV7;

    protected $table = 'payment_receipts';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'bank_transfer_id',
        'uploaded_by',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<BankTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BankTransfer::class, 'bank_transfer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

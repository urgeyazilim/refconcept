<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Inventory\Enums\MovementType;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change to a stock balance, forever.
 *
 * Append-only, enforced by a database trigger. The balance after the movement is
 * stored alongside it: reconstructing it by replaying every movement is possible but
 * slow, and having both means a disagreement between them is detectable rather than
 * merely theoretical.
 *
 * @property string $id
 * @property string $stock_item_id
 * @property MovementType $type
 * @property int $quantity signed — a receipt is positive, a dispatch negative
 * @property int $on_hand_after
 * @property int $reserved_after
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $reason
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class StockMovement extends Model
{
    use HasUuidV7;

    protected $table = 'stock_movements';

    /** Only `created_at` exists: a row that can never change has no updated_at. */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'stock_item_id',
        'type',
        'quantity',
        'on_hand_after',
        'reserved_after',
        'reference_type',
        'reference_id',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

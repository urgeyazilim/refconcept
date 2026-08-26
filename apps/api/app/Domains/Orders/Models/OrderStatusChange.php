<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One status change. Append-only, enforced by a database trigger.
 *
 * "When did this become shipped, and who said so" is the question every dispute starts
 * with, and a table that can be edited cannot answer it.
 *
 * @property string $id
 * @property string|null $order_id
 * @property string|null $seller_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $changed_by
 * @property string $actor_role
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class OrderStatusChange extends Model
{
    use HasUuidV7;

    protected $table = 'order_status_history';

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = ['actor_role' => 'system'];

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'seller_order_id',
        'from_status',
        'to_status',
        'changed_by',
        'actor_role',
        'reason',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

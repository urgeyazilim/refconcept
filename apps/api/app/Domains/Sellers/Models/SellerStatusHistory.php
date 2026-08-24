<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every status change an application or seller has been through.
 *
 * Separate from the general audit log because operations reads this constantly —
 * "why is this seller suspended" needs one indexed query, not a scan of a table that
 * holds every action on the platform.
 *
 * @property string $id
 * @property string|null $seller_id
 * @property string|null $application_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string|null $changed_by
 * @property Carbon $changed_at
 */
class SellerStatusHistory extends Model
{
    use HasUuidV7;

    protected $table = 'seller_status_history';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'application_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<SellerApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(SellerApplication::class, 'application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

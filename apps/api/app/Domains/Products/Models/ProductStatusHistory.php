<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every status change a product has been through.
 *
 * `field` records which status moved — a product has two independent ones, and
 * conflating them would make the history unreadable.
 *
 * @property string $id
 * @property string $product_id
 * @property string $field
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string|null $changed_by
 * @property Carbon $changed_at
 */
class ProductStatusHistory extends Model
{
    use HasUuidV7;

    protected $table = 'product_status_history';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'field',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

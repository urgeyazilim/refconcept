<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Identity\Models\User;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One moderation decision on a product.
 *
 * `flagged_fields` names what the reviewer objected to. Without it a rejection is a
 * paragraph the seller has to interpret, and the usual outcome is a resubmission with
 * the same problem.
 *
 * @property string $id
 * @property string $product_id
 * @property string $decision
 * @property string $reason
 * @property array<int, string>|null $flagged_fields
 * @property string|null $decided_by
 * @property Carbon $decided_at
 */
class ProductModeration extends Model
{
    use HasUuidV7;

    protected $table = 'product_moderation';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'decision',
        'reason',
        'flagged_fields',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flagged_fields' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

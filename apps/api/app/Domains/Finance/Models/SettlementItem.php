<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Orders\Models\SellerOrder;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seller order inside a payout run.
 *
 * The unique index on `seller_order_id` is the important part: an order can appear in
 * exactly one settlement, ever. Without it a re-run of the builder pays the same order
 * twice, and the second payment is a bank transfer nobody can recall.
 *
 * @property string $id
 * @property string $settlement_id
 * @property string $seller_order_id
 * @property int $gross_minor
 * @property int $commission_minor
 * @property int $net_minor
 */
class SettlementItem extends Model
{
    use HasUuidV7;

    protected $table = 'settlement_items';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'settlement_id',
        'seller_order_id',
        'gross_minor',
        'commission_minor',
        'net_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_minor' => 'integer',
            'commission_minor' => 'integer',
            'net_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Settlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /** @return BelongsTo<SellerOrder, $this> */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }
}

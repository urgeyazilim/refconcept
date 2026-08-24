<?php

declare(strict_types=1);

namespace App\Domains\Pricing\Models;

use App\Domains\Products\Models\ProductSku;
use App\Support\Casts\MoneyCast;
use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One SKU's price inside one list.
 *
 * Amounts are {@see Money} over integer minor-unit columns, exactly as on the SKU
 * itself — a price that changes representation when it moves between tables is a
 * price that will eventually be rounded on the way.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string $sku_id
 * @property Money $list_price_minor
 * @property Money|null $sale_price_minor
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PriceListItem extends Model
{
    use HasUuidV7;

    protected $table = 'price_list_items';

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'TRY',
    ];

    /** @var list<string> */
    protected $fillable = [
        'price_list_id',
        'sku_id',
        'list_price_minor',
        'sale_price_minor',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list_price_minor' => MoneyCast::class.':currency',
            'sale_price_minor' => MoneyCast::class.':currency',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** What a customer would pay under this list. */
    public function effectivePrice(): Money
    {
        return $this->sale_price_minor ?? $this->list_price_minor;
    }
}

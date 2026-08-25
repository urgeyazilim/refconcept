<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Matching\Models\DesignMatch;
use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a basket.
 *
 * The price is what it cost when it went in, not what it costs now. Everything interesting
 * about carts follows from that: a price can move between adding something and paying for
 * it, and the only handling a customer would call fair is to show them both numbers — which
 * needs the old one kept.
 *
 * `list_price_minor` is kept beside it so the two kinds of change can be told apart. A
 * discount ending and a price rising produce the same new figure and are different
 * sentences, and a customer who is told the wrong one will feel misled by the right one.
 *
 * @property string $id
 * @property string $cart_id
 * @property string $sku_id
 * @property string $product_id
 * @property string $seller_id
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $list_price_minor
 * @property int $tax_rate_bps
 * @property Carbon|null $price_changed_at
 * @property string|null $design_match_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CartItem extends Model
{
    use HasUuidV7;

    protected $table = 'cart_items';

    /** @var array<string, mixed> */
    protected $attributes = ['quantity' => 1, 'tax_rate_bps' => 2000];

    /** @var list<string> */
    protected $fillable = [
        'cart_id',
        'sku_id',
        'product_id',
        'seller_id',
        'quantity',
        'unit_price_minor',
        'list_price_minor',
        'tax_rate_bps',
        'design_match_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'list_price_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'price_changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<DesignMatch, $this> */
    public function designMatch(): BelongsTo
    {
        return $this->belongsTo(DesignMatch::class, 'design_match_id');
    }

    public function lineTotalMinor(): int
    {
        return $this->unit_price_minor * $this->quantity;
    }

    /**
     * The KDV contained in this line.
     *
     * Turkish prices are quoted inclusive, so the tax is a portion *of* the total rather
     * than an addition to it: at 20%, a 120₺ price contains 20₺ of tax, not 24₺. Getting
     * this backwards overcharges every customer, so the arithmetic is integer and stated
     * once here rather than repeated wherever a total is drawn.
     */
    public function taxMinor(): int
    {
        $gross = $this->lineTotalMinor();

        return (int) round($gross * $this->tax_rate_bps / (10_000 + $this->tax_rate_bps));
    }

    /** Whether the snapshot no longer matches what the offer costs today. */
    public function priceHasMoved(): bool
    {
        $this->loadMissing('sku');

        $current = $this->sku?->effectivePrice()->amountMinor;

        return $current !== null && $current !== $this->unit_price_minor;
    }
}

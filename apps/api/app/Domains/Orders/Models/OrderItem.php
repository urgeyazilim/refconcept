<?php

declare(strict_types=1);

namespace App\Domains\Orders\Models;

use App\Domains\Products\Models\Product;
use App\Domains\Products\Models\ProductSku;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line, frozen at the moment of the order.
 *
 * The name, the SKU code and the image are copied rather than joined. A product renamed,
 * unlisted or deleted must not change what an order from last month says was bought — an
 * order that renders differently after a catalogue edit is not a record of anything.
 *
 * @property string $id
 * @property string $order_id
 * @property string $seller_order_id
 * @property string $seller_id
 * @property string|null $product_id
 * @property string|null $sku_id
 * @property string $product_name
 * @property string|null $sku_code
 * @property string|null $variant_label
 * @property string|null $image_url
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int|null $list_price_minor
 * @property int $tax_rate_bps
 * @property int $line_total_minor
 * @property int $tax_minor
 * @property int $commission_rate_bps
 * @property int $commission_minor
 * @property string|null $design_match_id
 */
class OrderItem extends Model
{
    use HasUuidV7;

    protected $table = 'order_items';

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'seller_order_id',
        'seller_id',
        'product_id',
        'sku_id',
        'product_name',
        'sku_code',
        'variant_label',
        'image_url',
        'quantity',
        'unit_price_minor',
        'list_price_minor',
        'tax_rate_bps',
        'line_total_minor',
        'tax_minor',
        'commission_rate_bps',
        'commission_minor',
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
            'line_total_minor' => 'integer',
            'tax_minor' => 'integer',
            'commission_rate_bps' => 'integer',
            'commission_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<SellerOrder, $this> */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /**
     * The snapshot, plus a link only where the thing still exists.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_name' => $this->product_name,
            'sku_code' => $this->sku_code,
            'variant_label' => $this->variant_label,
            'image_url' => $this->image_url,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'line_total_minor' => $this->line_total_minor,
            'tax_minor' => $this->tax_minor,
            // Kept so a design can show what was bought from it.
            'design_match_id' => $this->design_match_id,
        ];
    }
}

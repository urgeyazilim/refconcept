<?php

declare(strict_types=1);

namespace App\Domains\Products\Models;

use App\Domains\Products\Enums\SkuStatus;
use App\Domains\Products\Enums\StockPolicy;
use App\Domains\Sellers\Models\Seller;
use App\Support\Casts\MoneyCast;
use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One seller's offer of a product.
 *
 * Prices are {@see Money}, backed by integer minor-unit columns. Nothing in this
 * model hands out a bare number for an amount: a bare number is what lets somebody
 * add two prices in different currencies, or divide a total and lose a kuruş.
 *
 * @property string $id
 * @property string $product_id
 * @property string $seller_id
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $variant_label
 * @property SkuStatus $status
 * @property string $currency
 * @property Money $list_price_minor
 * @property Money|null $sale_price_minor
 * @property int $tax_rate_bps
 * @property StockPolicy $stock_policy
 * @property int $stock_quantity
 * @property int $lead_time_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class ProductSku extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'product_skus';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'TRY',
        'stock_policy' => 'track',
        'tax_rate_bps' => 2000,
    ];

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'seller_id',
        'sku',
        'barcode',
        'variant_label',
        'currency',
        'list_price_minor',
        'sale_price_minor',
        'tax_rate_bps',
        'stock_policy',
        'stock_quantity',
        'lead_time_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SkuStatus::class,
            'stock_policy' => StockPolicy::class,
            'list_price_minor' => MoneyCast::class.':currency',
            'sale_price_minor' => MoneyCast::class.':currency',
            'tax_rate_bps' => 'integer',
            'stock_quantity' => 'integer',
            'lead_time_days' => 'integer',
        ];
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

    /** @return HasOne<ProductDimension, $this> */
    public function dimensions(): HasOne
    {
        return $this->hasOne(ProductDimension::class, 'sku_id');
    }

    /** The price a customer actually pays: the sale price when there is one. */
    public function effectivePrice(): Money
    {
        return $this->sale_price_minor ?? $this->list_price_minor;
    }

    public function isDiscounted(): bool
    {
        return $this->sale_price_minor !== null
            && $this->sale_price_minor->lessThan($this->list_price_minor);
    }

    /** Discount in basis points, so the figure stays exact for display and reporting. */
    public function discountBps(): int
    {
        if (! $this->isDiscounted() || $this->list_price_minor->isZero()) {
            return 0;
        }

        $saved = $this->list_price_minor->subtract($this->effectivePrice())->amountMinor;

        return (int) round(($saved * 10_000) / $this->list_price_minor->amountMinor);
    }

    /** Tax on the effective price, computed from basis points rather than a rate float. */
    public function taxAmount(): Money
    {
        return $this->effectivePrice()->percentage($this->tax_rate_bps);
    }

    /**
     * Whether this offer can be sold right now.
     *
     * Availability is not just a status: a tracked SKU with no stock is not
     * purchasable however active it claims to be, and a suspended seller's offers are
     * not purchasable at all.
     */
    public function isAvailable(): bool
    {
        if (! $this->status->isPurchasable()) {
            return false;
        }

        if ($this->stock_policy->tracksQuantity() && $this->stock_quantity <= 0) {
            return false;
        }

        return $this->seller?->canTrade() ?? false;
    }

    /** @param  Builder<$this>  $query */
    public function scopePurchasable(Builder $query): void
    {
        $query->where('status', SkuStatus::Active->value)
            ->where(function (Builder $stock): void {
                $stock->where('stock_policy', '!=', StockPolicy::Track->value)
                    ->orWhere('stock_quantity', '>', 0);
            })
            ->whereHas('seller', function (Builder $seller): void {
                $seller->where('status', 'active');
            });
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSeller(Builder $query, string $sellerId): void
    {
        $query->where('seller_id', $sellerId);
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Enums\LocationType;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Where a seller's stock physically is.
 *
 * @property string $id
 * @property string $seller_id
 * @property string $code
 * @property string $name
 * @property LocationType $type
 * @property string|null $city
 * @property string $country_code
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class StockLocation extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'stock_locations';

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'warehouse',
        'country_code' => 'TR',
        'is_default' => false,
        'is_active' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'code',
        'name',
        'type',
        'city',
        'country_code',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return HasMany<StockItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class, 'location_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSeller(Builder $query, string $sellerId): void
    {
        $query->where('seller_id', $sellerId);
    }
}

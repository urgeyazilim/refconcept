<?php

declare(strict_types=1);

namespace App\Domains\Pricing\Models;

use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A named set of prices a seller can apply to their own SKUs.
 *
 * Every seller has exactly one default list, guaranteed by a partial unique index.
 * Additional lists are how a campaign is expressed *without overwriting* the everyday
 * price — which is the only reason "put the prices back when the campaign ends" is a
 * thing that can be done rather than a thing somebody has to remember.
 *
 * @property string $id
 * @property string $seller_id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property bool $is_default
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class PriceList extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $table = 'price_lists';

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'TRY',
        'is_default' => false,
        'status' => 'active',
    ];

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'code',
        'name',
        'currency',
        'is_default',
        'status',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * Whether this list applies right now.
     *
     * The default list has no window and always applies; a campaign list applies only
     * inside its own. Both conditions are checked here and in {@see scopeEffective()},
     * and the two must agree — a list that the query says is live and the model says
     * is not would price the catalogue differently from the product page.
     */
    public function isEffective(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        return $this->ends_at === null || $at->lt($this->ends_at);
    }

    /** @param  Builder<$this>  $query */
    public function scopeEffective(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSeller(Builder $query, string $sellerId): void
    {
        $query->where('seller_id', $sellerId);
    }
}

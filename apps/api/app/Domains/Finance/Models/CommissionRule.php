<?php

declare(strict_types=1);

namespace App\Domains\Finance\Models;

use App\Domains\Catalog\Models\Category;
use App\Domains\Sellers\Models\Seller;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rung of the commission hierarchy.
 *
 * @property string $id
 * @property string $scope
 * @property string|null $seller_id
 * @property string|null $category_id
 * @property int $rate_bps
 * @property int $priority
 * @property string|null $label
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property-read Seller|null $seller
 * @property-read Category|null $category
 */
class CommissionRule extends Model
{
    use HasUuidV7;

    protected $table = 'commission_rules';

    /** @var array<string, mixed> */
    protected $attributes = ['priority' => 100, 'is_active' => true];

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'seller_id',
        'category_id',
        'rate_bps',
        'priority',
        'label',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Active and inside its window.
     *
     * A rule with no window is always in it; a campaign's window is the whole point of a
     * campaign.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = now();

        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}

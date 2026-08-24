<?php

declare(strict_types=1);

namespace App\Domains\Pricing\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Products\Models\ProductSku;
use App\Support\Concerns\HasUuidV7;
use App\Support\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every price change, forever.
 *
 * Append-only, enforced by a database trigger rather than by convention. A price is
 * the most disputed number in a marketplace — "it said 3.900 yesterday" — and a
 * history that somebody with database access can quietly edit answers nothing.
 *
 * The source column matters as much as the numbers: a price that dropped 40% because
 * of a spreadsheet import with a decimal in the wrong place looks identical to a
 * deliberate campaign until you can see where it came from.
 *
 * @property string $id
 * @property string $sku_id
 * @property string|null $price_list_id
 * @property string $field
 * @property int|null $old_value_minor
 * @property int|null $new_value_minor
 * @property string $currency
 * @property string $source
 * @property string|null $changed_by
 * @property Carbon $changed_at
 */
class PriceHistory extends Model
{
    use HasUuidV7;

    protected $table = 'price_history';

    /** A row that can never change has no created_at/updated_at worth keeping. */
    public $timestamps = false;

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'TRY',
        'source' => 'manual',
    ];

    /** @var list<string> */
    protected $fillable = [
        'sku_id',
        'price_list_id',
        'field',
        'old_value_minor',
        'new_value_minor',
        'currency',
        'source',
        'changed_by',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value_minor' => 'integer',
            'new_value_minor' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductSku, $this> */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(ProductSku::class, 'sku_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function oldPrice(): ?Money
    {
        return $this->old_value_minor === null ? null : Money::of($this->old_value_minor, $this->currency);
    }

    public function newPrice(): ?Money
    {
        return $this->new_value_minor === null ? null : Money::of($this->new_value_minor, $this->currency);
    }

    /** The change in basis points, so a report does not have to divide two integers. */
    public function changeBps(): ?int
    {
        $old = $this->old_value_minor;
        $new = $this->new_value_minor;

        if ($old === null || $new === null || $old === 0) {
            return null;
        }

        return (int) round((($new - $old) * 10_000) / $old);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForSku(Builder $query, string $skuId): void
    {
        $query->where('sku_id', $skuId)->orderByDesc('changed_at');
    }
}

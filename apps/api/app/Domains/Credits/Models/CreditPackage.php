<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A bundle of credits with a price.
 *
 * `credits` and `bonus_credits` are separate because the customer paid for one and was
 * given the other. That distinction survives into the accounts, matters when a purchase
 * is refunded, and lets a listing say "500 + 50 hediye" honestly rather than advertising
 * 550 at the price of 500.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $credits
 * @property int $bonus_credits
 * @property int $price_minor
 * @property string $currency
 * @property int|null $validity_days
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditPackage extends Model
{
    use HasUuidV7;

    protected $table = 'credit_packages';

    /** @var array<string, mixed> */
    protected $attributes = [
        'bonus_credits' => 0,
        'currency' => 'TRY',
        'is_active' => true,
        'is_featured' => false,
        'position' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'credits',
        'bonus_credits',
        'price_minor',
        'currency',
        'validity_days',
        'is_active',
        'is_featured',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'bonus_credits' => 'integer',
            'price_minor' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function totalCredits(): int
    {
        return $this->credits + $this->bonus_credits;
    }

    /**
     * What one credit costs, in minor units scaled by ten thousand.
     *
     * Scaled and kept an integer so the number a comparison table sorts on is never a
     * float. Only ever divided at the point of display; never used in a charge.
     */
    public function unitPriceScaled(): int
    {
        $total = $this->totalCredits();

        return $total === 0 ? 0 : intdiv($this->price_minor * 10_000, $total);
    }

    public function expiresAt(?Carbon $from = null): ?Carbon
    {
        if ($this->validity_days === null) {
            return null;
        }

        return ($from ?? now())->copy()->addDays($this->validity_days);
    }

    /** @param  Builder<$this>  $query */
    public function scopePurchasable(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position')->orderBy('price_minor');
    }
}

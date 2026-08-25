<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Commerce\Enums\CartStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\DesignVersion;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One customer's basket.
 *
 * A marketplace basket, which means several sellers in one place. The grouping is a
 * property of the lines rather than of the cart — each line records who is selling it —
 * because that is what eventually becomes several seller orders and several payouts.
 *
 * Carts are kept after checkout rather than deleted. "What was in the basket when they
 * gave up" is the first question anybody asks about conversion, and a deleted row cannot
 * answer it.
 *
 * @property string $id
 * @property string $user_id
 * @property CartStatus $status
 * @property string $currency
 * @property string|null $design_version_id
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $checked_out_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Cart extends Model
{
    use HasUuidV7;

    protected $table = 'carts';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'currency' => 'TRY'];

    /** @var list<string> */
    protected $fillable = ['user_id', 'currency', 'design_version_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'last_activity_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('created_at');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function designVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'design_version_id');
    }

    /**
     * The lines, grouped by who is selling them.
     *
     * The shape a marketplace basket is actually read in: a customer wants to know what is
     * coming from where, because that is what decides how many parcels arrive and when.
     *
     * @return Collection<array-key, EloquentCollection<int, CartItem>>
     */
    public function bySeller(): Collection
    {
        $this->loadMissing(['items.seller', 'items.product.media', 'items.sku']);

        return $this->items->groupBy('seller_id');
    }

    /** What the basket costs, before shipping. */
    public function subtotalMinor(): int
    {
        $this->loadMissing('items');

        return (int) $this->items->sum(
            static fn (CartItem $item): int => $item->lineTotalMinor(),
        );
    }

    /**
     * Tax included in the subtotal.
     *
     * Turkish prices are quoted inclusive of KDV, so this is the portion *within* the
     * total rather than something added to it. Getting that backwards would overcharge
     * every customer by twenty per cent, so it is computed per line from the rate the line
     * recorded rather than from a rate looked up now.
     */
    public function taxMinor(): int
    {
        $this->loadMissing('items');

        return (int) $this->items->sum(
            static fn (CartItem $item): int => $item->taxMinor(),
        );
    }

    public function itemCount(): int
    {
        $this->loadMissing('items');

        return (int) $this->items->sum('quantity');
    }

    public function isEmpty(): bool
    {
        $this->loadMissing('items');

        return $this->items->isEmpty();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [CartStatus::Open->value, CartStatus::CheckingOut->value]);
    }
}

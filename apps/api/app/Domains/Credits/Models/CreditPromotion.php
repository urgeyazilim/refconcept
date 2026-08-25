<?php

declare(strict_types=1);

namespace App\Domains\Credits\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A code that grants credits.
 *
 * Two limits, answering two different questions. `max_redemptions` protects the budget —
 * a code that reaches a public forum must not be able to give away an unbounded number
 * of credits. `max_per_user` protects against one person redeeming repeatedly, which is
 * the far commoner abuse and which a total does nothing to prevent.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $credits
 * @property int|null $validity_days
 * @property int|null $max_redemptions
 * @property int $max_per_user
 * @property int $redemption_count
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $new_accounts_only
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CreditPromotion extends Model
{
    use HasUuidV7;

    protected $table = 'credit_promotions';

    /** @var array<string, mixed> */
    protected $attributes = [
        'max_per_user' => 1,
        'redemption_count' => 0,
        'new_accounts_only' => false,
        'is_active' => true,
    ];

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'credits',
        'validity_days',
        'max_redemptions',
        'max_per_user',
        'starts_at',
        'ends_at',
        'new_accounts_only',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'validity_days' => 'integer',
            'max_redemptions' => 'integer',
            'max_per_user' => 'integer',
            'redemption_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'new_accounts_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<CreditPromotionRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CreditPromotionRedemption::class, 'promotion_id');
    }

    /**
     * Whether the campaign itself is running.
     *
     * Deliberately says nothing about a particular person — that needs a lock and a
     * count, and folding the two together would produce a method whose `false` has two
     * very different explanations to give a customer.
     */
    public function isRunning(?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $at->gte($this->ends_at)) {
            return false;
        }

        return $this->max_redemptions === null || $this->redemption_count < $this->max_redemptions;
    }

    public function expiresAt(?Carbon $from = null): ?Carbon
    {
        if ($this->validity_days === null) {
            return null;
        }

        return ($from ?? now())->copy()->addDays($this->validity_days);
    }

    public function remainingRedemptions(): ?int
    {
        return $this->max_redemptions === null
            ? null
            : max(0, $this->max_redemptions - $this->redemption_count);
    }

    /** @param  Builder<$this>  $query */
    public function scopeRunning(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }
}

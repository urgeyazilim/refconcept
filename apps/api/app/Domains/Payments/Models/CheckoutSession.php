<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Commerce\Models\Cart;
use App\Domains\Credits\Models\CreditPackage;
use App\Domains\Identity\Models\User;
use App\Domains\Payments\Enums\CheckoutPurpose;
use App\Domains\Payments\Enums\CheckoutStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a customer agreed to buy, frozen.
 *
 * The session exists so that the thing being paid for cannot move while it is being paid
 * for. Between "checkout" and "paid" there is a browser redirect, a bank, a 3DS page and
 * sometimes several minutes — and in those minutes the seller can reprice, the customer
 * can open another tab and empty the basket, and the address book can be edited. None of
 * that may change the amount charged or the address promised, so the totals and the
 * addresses are copied in and the session stops asking anybody.
 *
 * @property string $id
 * @property string $user_id
 * @property CheckoutPurpose $purpose
 * @property CheckoutStatus $status
 * @property string|null $cart_id
 * @property string|null $credit_package_id
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $shipping_minor
 * @property int $tax_minor
 * @property int $grand_total_minor
 * @property array<int, array<string, mixed>>|null $lines
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Cart|null $cart
 * @property-read CreditPackage|null $creditPackage
 * @property-read Collection<int, PaymentIntent> $intents
 */
class CheckoutSession extends Model
{
    use HasUuidV7;

    protected $table = 'checkout_sessions';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'currency' => 'TRY',
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'shipping_minor' => 0,
        'tax_minor' => 0,
        'grand_total_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'purpose',
        'status',
        'cart_id',
        'credit_package_id',
        'shipping_address',
        'billing_address',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'shipping_minor',
        'tax_minor',
        'grand_total_minor',
        'lines',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => CheckoutPurpose::class,
            'status' => CheckoutStatus::class,
            'shipping_address' => 'array',
            'billing_address' => 'array',
            'lines' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'shipping_minor' => 'integer',
            'tax_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<CreditPackage, $this> */
    public function creditPackage(): BelongsTo
    {
        return $this->belongsTo(CreditPackage::class, 'credit_package_id');
    }

    /** @return HasMany<PaymentIntent, $this> */
    public function intents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'checkout_session_id');
    }

    /**
     * The attempt still in flight, if there is one.
     *
     * At most one can exist — a partial unique index says so — which is what makes "pay"
     * safe to click twice.
     */
    public function liveIntent(): ?PaymentIntent
    {
        return $this->intents()
            ->whereIn('status', ['created', 'requires_action', 'processing', 'authorized'])
            ->latest('created_at')
            ->first();
    }

    public function paidIntent(): ?PaymentIntent
    {
        return $this->intents()
            ->whereIn('status', ['captured', 'partially_refunded', 'refunded'])
            ->latest('captured_at')
            ->first();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param  Builder<$this>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [
            CheckoutStatus::Open->value,
            CheckoutStatus::AwaitingPayment->value,
            CheckoutStatus::Failed->value,
        ]);
    }
}

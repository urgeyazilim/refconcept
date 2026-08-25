<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Services\PaymentProcessor;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attempt to collect one amount.
 *
 * Not one row per checkout: a declined card is tried again, sometimes with a different
 * card or a different provider, and each of those is a separate conversation with its own
 * external id and its own outcome. Collapsing them would lose the history a chargeback is
 * argued from.
 *
 * The status is only ever written through {@see PaymentProcessor},
 * which checks {@see PaymentStatus::canTransitionTo()} first. Assigning it directly is the
 * bug this class exists to prevent — a late webhook would otherwise move a captured
 * payment back to processing, and then the money is real and the record says it is not.
 *
 * @property string $id
 * @property string $checkout_session_id
 * @property string $user_id
 * @property string $gateway
 * @property string $method
 * @property PaymentStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property int $captured_minor
 * @property int $refunded_minor
 * @property string|null $external_id
 * @property string|null $redirect_url
 * @property array<string, mixed>|null $details
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $attempts
 * @property Carbon|null $authorized_at
 * @property Carbon|null $captured_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CheckoutSession|null $session
 * @property-read User|null $user
 * @property-read Collection<int, PaymentTransaction> $transactions
 */
class PaymentIntent extends Model
{
    use HasUuidV7;

    protected $table = 'payment_intents';

    /** @var array<string, mixed> */
    protected $attributes = [
        'method' => 'card',
        'status' => 'created',
        'currency' => 'TRY',
        'captured_minor' => 0,
        'refunded_minor' => 0,
        'attempts' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'checkout_session_id',
        'user_id',
        'gateway',
        'method',
        'amount_minor',
        'currency',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'details' => 'array',
            'amount_minor' => 'integer',
            'captured_minor' => 'integer',
            'refunded_minor' => 'integer',
            'attempts' => 'integer',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CheckoutSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_intent_id');
    }

    /** What could still be sent back. */
    public function refundableMinor(): int
    {
        return max(0, $this->captured_minor - $this->refunded_minor);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * The shape shown to a customer.
     *
     * The external id is included — it is what the customer's bank statement and our
     * support both refer to — but nothing from `details` is, because that is the
     * provider's echo and echoing it back is how something we did not expect ends up on a
     * page. The failure message is ours and already in Turkish.
     *
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'gateway' => $this->gateway,
            'method' => $this->method,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'captured_minor' => $this->captured_minor,
            'refunded_minor' => $this->refunded_minor,
            'reference' => $this->external_id,
            'redirect_url' => $this->status === PaymentStatus::RequiresAction ? $this->redirect_url : null,
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

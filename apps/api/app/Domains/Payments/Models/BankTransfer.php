<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Payments\Enums\BankTransferStatus;
use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One expected transfer, hanging off a payment intent.
 *
 * The intent keeps the state machine, the append-only record and the fulfilment path; this
 * row knows only the things a bank transfer has that a card payment does not — a reference
 * to quote, an amount that may not match, and a person who confirmed it.
 *
 * @property string $id
 * @property string $payment_intent_id
 * @property string|null $bank_account_id
 * @property string $reference
 * @property BankTransferStatus $status
 * @property int $expected_minor
 * @property int|null $received_minor
 * @property string $currency
 * @property Carbon|null $value_date
 * @property Carbon|null $expires_at
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentIntent|null $intent
 * @property-read PaymentBankAccount|null $bankAccount
 * @property-read User|null $confirmedBy
 * @property-read Collection<int, PaymentReceipt> $receipts
 */
class BankTransfer extends Model
{
    use HasUuidV7;

    protected $table = 'bank_transfers';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'awaiting_transfer',
        'currency' => 'TRY',
    ];

    /** @var list<string> */
    protected $fillable = [
        'payment_intent_id',
        'bank_account_id',
        'reference',
        'expected_minor',
        'currency',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BankTransferStatus::class,
            'expected_minor' => 'integer',
            'received_minor' => 'integer',
            'value_date' => 'date',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return BelongsTo<PaymentBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentBankAccount::class, 'bank_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasMany<PaymentReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class, 'bank_transfer_id');
    }

    /** What is still owed. Negative when too much arrived. */
    public function shortfallMinor(): int
    {
        return $this->expected_minor - ($this->received_minor ?? 0);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [
            BankTransferStatus::AwaitingTransfer->value,
            BankTransferStatus::UnderReview->value,
            BankTransferStatus::ShortPaid->value,
        ]);
    }

    /**
     * What the customer is shown. No receipt paths: those are private files reached
     * only through a signed link after an ownership check.
     *
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message' => $this->status->customerMessage(),
            'expected_minor' => $this->expected_minor,
            'received_minor' => $this->received_minor,
            'shortfall_minor' => $this->status === BankTransferStatus::ShortPaid ? $this->shortfallMinor() : null,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'bank_account' => $this->bankAccount?->toCustomerArray(),
            'receipt_count' => $this->receipts()->count(),
        ];
    }
}

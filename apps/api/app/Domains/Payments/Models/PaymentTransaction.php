<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to a provider and what came back. Append-only.
 *
 * The database refuses UPDATE and DELETE on this table, so `$timestamps` is off for
 * `updated_at` — a model that tried to touch it would hit the trigger, and a financial
 * record that can be touched is not a financial record.
 *
 * The amount is always positive; the direction is the type. A refund written as a negative
 * capture would make every sum in every report ambiguous.
 *
 * @property string $id
 * @property string $payment_intent_id
 * @property string $gateway
 * @property string $type
 * @property string $status
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $external_id
 * @property string|null $external_reference
 * @property string|null $request_fingerprint
 * @property array<string, mixed>|null $response
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $idempotency_key
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read PaymentIntent|null $intent
 */
class PaymentTransaction extends Model
{
    use HasUuidV7;

    protected $table = 'payment_transactions';

    public const UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'succeeded',
        'currency' => 'TRY',
        'amount_minor' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'payment_intent_id',
        'gateway',
        'type',
        'status',
        'amount_minor',
        'currency',
        'external_id',
        'external_reference',
        'request_fingerprint',
        'response',
        'error_code',
        'error_message',
        'idempotency_key',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Payments\Models;

use App\Support\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a provider told us, stored before it was understood.
 *
 * The row exists whether or not we can make sense of the event, whether or not the
 * signature checked out, and whether or not it turns out to be a duplicate. That is the
 * point of an inbox: the provider is waiting on the other end of a socket and will retry
 * if we are slow, so the endpoint writes the row and answers 200, and the meaning is
 * worked out afterwards by a queued job.
 *
 * @property string $id
 * @property string $gateway
 * @property string|null $event_type
 * @property string|null $external_event_id
 * @property string $body_fingerprint
 * @property bool $signature_verified
 * @property array<string, mixed>|null $headers
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property string|null $payment_intent_id
 * @property int $attempts
 * @property string|null $error_message
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentIntent|null $intent
 */
class PaymentWebhookEvent extends Model
{
    use HasUuidV7;

    protected $table = 'payment_webhook_events';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'received',
        'signature_verified' => false,
        'attempts' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'gateway',
        'event_type',
        'external_event_id',
        'body_fingerprint',
        'signature_verified',
        'headers',
        'payload',
        'status',
        'payment_intent_id',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'headers' => 'array',
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', ['received', 'failed']);
    }
}

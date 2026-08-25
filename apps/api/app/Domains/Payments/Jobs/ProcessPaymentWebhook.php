<?php

declare(strict_types=1);

namespace App\Domains\Payments\Jobs;

use App\Domains\Payments\Models\PaymentWebhookEvent;
use App\Domains\Payments\Services\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Works out what one stored webhook means.
 *
 * The event is already persisted and already acknowledged to the provider by the time
 * this runs. That is the point: the provider is not waiting, so this may take as long as
 * it needs, may fail, and may be retried without a second delivery being triggered.
 *
 * The id is passed rather than the model, because a serialised model is a snapshot of a
 * row as it was when the job was queued, and this one is written to as it is processed.
 *
 * Retries are generous — eight attempts backing off — because the alternative to
 * retrying a payment confirmation is a customer who paid and has nothing to show for it.
 * A backoff rather than an immediate retry, so a database that is briefly unavailable is
 * not hammered by every webhook in the queue at once.
 */
final class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 15, 60, 120, 300, 600, 900];

    public function __construct(private readonly string $eventId) {}

    public function handle(WebhookProcessor $processor): void
    {
        $event = PaymentWebhookEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $processor->process($event);
    }

    /**
     * The last attempt has failed.
     *
     * Marked rather than left mid-flight, because `processing` forever is indistinguishable
     * from "a worker is on it" and nobody would ever look. A `failed` payment event is a
     * thing an operator can find and replay.
     */
    public function failed(Throwable $e): void
    {
        PaymentWebhookEvent::query()
            ->whereKey($this->eventId)
            ->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 300),
            ]);
    }
}

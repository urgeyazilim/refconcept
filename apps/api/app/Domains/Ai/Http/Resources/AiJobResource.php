<?php

declare(strict_types=1);

namespace App\Domains\Ai\Http\Resources;

use App\Domains\Ai\Models\AiJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One AI job, as its owner sees it.
 *
 * Written for a client that is polling. Everything a spinner needs to decide what to
 * show is here — status, whether it is worth asking again, and on failure a sentence in
 * Turkish that names the problem rather than the provider.
 *
 * What is deliberately absent is anything about *how* the answer was produced: which
 * provider, which model, what it cost. A customer has no use for it, and publishing our
 * model choices on an endpoint anybody with an account can call would hand a competitor
 * the one piece of the stack that took the longest to tune.
 *
 * @mixin AiJob
 */
final class AiJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task' => $this->task->value,
            'task_label' => $this->task->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Saves a client from having to know which statuses are terminal, and from
            // polling a finished job forever when it gets that list wrong.
            'is_finished' => $this->status->isTerminal(),

            'output' => $this->output,

            /*
             * The failure kind, not the provider's message. "Zaman aşımı" is something a
             * customer can act on; the raw text from an HTTP client is not, and it can
             * carry request ids and model names that are nobody else's business.
             */
            'failure' => $this->failure_kind === null ? null : [
                'kind' => $this->failure_kind->value,
                'label' => $this->failure_kind->label(),
                'is_retryable' => $this->failure_kind->isRetryable(),
            ],

            'credit_cost' => $this->credit_cost,
            'duration_ms' => $this->wallClockMs(),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}

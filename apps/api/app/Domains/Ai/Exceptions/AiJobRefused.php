<?php

declare(strict_types=1);

namespace App\Domains\Ai\Exceptions;

use App\Domains\Ai\Enums\AiTask;
use RuntimeException;

/**
 * The request was turned away before anything was attempted.
 *
 * Distinct from a job that ran and failed, and the distinction is the customer-facing
 * one: a refusal means nothing was spent, nothing was recorded against their account,
 * and there is no job to look at. A failure means the opposite of all three.
 *
 * Carries an HTTP status because the two refusals want different ones — a paused
 * feature is a 503 that says "try later", and a concurrency limit is a 429 that says
 * "you specifically, try later" — and putting that mapping in the exception keeps every
 * controller that catches it from inventing its own answer.
 */
final class AiJobRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly AiTask $task,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(AiTask $task, string $reason): self
    {
        return new self($reason, $task, 503);
    }

    public static function tooManyInFlight(AiTask $task, int $inFlight, int $limit): self
    {
        return new self(
            sprintf(
                'Aynı anda en fazla %d %s işlemi çalıştırabilirsiniz; şu anda %d tane sürüyor.',
                $limit,
                mb_strtolower($task->label()),
                $inFlight,
            ),
            $task,
            429,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Ai\Contracts;

use App\Domains\Ai\Services\AiCall;
use App\Domains\Ai\Services\AiResult;

/**
 * What every model provider has to be able to do.
 *
 * Deliberately narrow. The gateway owns retries, fallback, cost accounting, timing and
 * persistence; an adapter's entire job is to turn one {@see AiCall} into one
 * {@see AiResult} and to translate the provider's own failure vocabulary into ours.
 * Every adapter that also retried, or logged, or decided a cost would be a second
 * place those policies live — and the second place is always the one that drifts.
 *
 * An adapter never throws for a provider-side failure. A refusal, a timeout and a rate
 * limit are all *answers* the gateway has to reason about, and an exception would
 * discard the classification that decides whether to retry.
 */
interface AiProvider
{
    /**
     * Runs one call. Never throws for a provider failure — returns a failed result.
     */
    public function execute(AiCall $call): AiResult;

    /**
     * Whether this adapter can serve the call at all.
     *
     * Checked before the call so a misconfiguration reports itself as a
     * misconfiguration rather than as a confusing provider error, which is the
     * difference between an operator fixing it in a minute and reading provider logs
     * for an hour.
     */
    public function supports(AiCall $call): bool;

    /** The `driver` value in `ai_providers` that selects this adapter. */
    public function driver(): string;
}

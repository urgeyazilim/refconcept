<?php

declare(strict_types=1);

namespace App\Domains\Ai\Services;

use App\Domains\Ai\Enums\AiJobStatus;
use App\Domains\Ai\Models\AiJob;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Credits\Models\CreditReservation;
use App\Domains\Credits\Services\CreditLedger;
use App\Domains\Identity\Models\User;

/**
 * What an AI job costs its owner.
 *
 * Deliberately a collaborator rather than something the gateway knows about. The gateway
 * decides which model runs and whether it worked; it has no business deciding whether
 * somebody can afford it, and giving it both jobs would mean every future caller of the
 * gateway inherits a billing decision it did not ask for.
 *
 * The sequence is hold-then-settle, and the order matters:
 *
 *  1. **Reserve before queueing.** A customer with three credits must be told so
 *     immediately, not given a job id and a failure four seconds later. The hold also
 *     stops them queueing ten renders they can only afford one of.
 *  2. **Consume on success, release on anything else.** A render that failed because a
 *     provider timed out is not something to charge for — that is our problem, not the
 *     customer's, and a charge for it is the fastest way to lose them.
 *
 * The reservation reference is the job id, which makes every operation here idempotent
 * for free: a retried dispatch finds the existing hold, and a job settled twice settles
 * once.
 */
final class AiJobCredits
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * Holds the job's cost, if it has one and somebody is paying.
     *
     * A zero-cost task — a search query rewrite, a support draft — holds nothing. Those
     * are paid for out of the platform's own budget rather than a customer's wallet, and
     * a reservation of zero would be a row that exists only to be released.
     *
     * @throws InsufficientCredits
     */
    public function hold(AiJob $job, ?User $user): ?CreditReservation
    {
        if ($user === null || $job->credit_cost <= 0) {
            return null;
        }

        return $this->ledger->reserve(
            user: $user,
            credits: $job->credit_cost,
            reference: $this->referenceFor($job),
            description: $job->task->label(),
            subject: $job,
            /*
             * Longer than any route's timeout plus its retries, so the sweeper never
             * releases a hold out from under a job that is still running. If it did, the
             * settle that followed would find no hold and the work would be free.
             */
            expiresAt: now()->addHours(2),
        );
    }

    /**
     * Turns the hold into a charge, or gives it back.
     *
     * Called once the job has reached a terminal state. Reads the reservation by the job
     * id rather than taking it as an argument, because the worker that settles is not the
     * process that reserved and passing the object between them would mean serialising a
     * row that may have moved on.
     */
    public function settle(AiJob $job): void
    {
        $reservation = CreditReservation::query()
            ->where('reference', $this->referenceFor($job))
            ->first();

        if ($reservation === null || ! $reservation->isHeld()) {
            return;
        }

        if ($job->status === AiJobStatus::Succeeded) {
            $this->ledger->consume($reservation, $job->task->label());

            return;
        }

        /*
         * Everything that is not a success gives the credits back, including a cancel.
         * The one debatable case is a job that failed on its third attempt after a
         * provider genuinely produced two answers we threw away — and it is still not the
         * customer's mistake, so it is still not their money.
         */
        $this->ledger->release(
            $reservation,
            sprintf('%s tamamlanamadı', $job->task->label()),
        );
    }

    /**
     * Releases without waiting for a terminal state.
     *
     * For the path where a job never runs at all — refused at the door, or deleted before
     * a worker picked it up — where there is no failure to settle from.
     */
    public function releaseFor(AiJob $job): void
    {
        $reservation = CreditReservation::query()
            ->where('reference', $this->referenceFor($job))
            ->first();

        if ($reservation === null || ! $reservation->isHeld()) {
            return;
        }

        $this->ledger->release($reservation, sprintf('%s iptal edildi', $job->task->label()));
    }

    /** One hold per job, addressed by the job's own id. */
    private function referenceFor(AiJob $job): string
    {
        return 'ai-job:'.$job->getKey();
    }
}

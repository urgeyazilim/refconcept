<?php

declare(strict_types=1);

namespace App\Domains\Ai\Policies;

use App\Domains\Ai\Models\AiJob;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;
use App\Providers\AppServiceProvider;

/**
 * Who may look at one AI job.
 *
 * A job's `input` is not metadata. For a room analysis it holds the link to a
 * photograph of somebody's living room and whatever they typed about how they live in
 * it, and for a support answer it holds their question. So the customer who owns the
 * job may read it, and nobody else may — including platform staff, who get the
 * operational view instead: which task, which model, how long, what it cost, and why it
 * failed, with the payload left out at the resource rather than trusted to a habit.
 *
 * That is the same line drawn for projects in {@see AppServiceProvider},
 * and drawn again here because a job is a second door into the same room. A route that
 * checked only "is this person an admin" would have made the first lock decorative.
 */
final class AiJobPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    /** Reading the payload: the owner alone. */
    public function view(User $user, AiJob $job): bool
    {
        return $job->user_id !== null && $job->user_id === $user->getKey();
    }

    /**
     * Reading the operational record without the payload.
     *
     * Separate from {@see view()} on purpose. Somebody has to be able to answer "are
     * renders failing this morning", and that question needs timings, costs and failure
     * kinds — none of which describe anybody's home.
     */
    public function viewOperations(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::SystemSettingsManage)
            || $this->access->hasPermission($user, Permission::AuditView);
    }

    /**
     * Cancelling is the owner's, while there is still something to cancel.
     *
     * A finished job cannot be cancelled and saying so is not pedantry: the credit is
     * already spent, and a UI that offers the button implies otherwise.
     */
    public function cancel(User $user, AiJob $job): bool
    {
        return $this->view($user, $job) && $job->status->isTerminal() === false;
    }
}

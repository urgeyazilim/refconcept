<?php

declare(strict_types=1);

namespace App\Domains\Projects\Policies;

use App\Domains\Identity\Models\User;
use App\Domains\Projects\Models\Project;
use App\Providers\AppServiceProvider;

/**
 * Who may see and change a project.
 *
 * The strictest policy in the system, and the simplest, because the two go together.
 * A project is somebody's home: the owner, and the people the owner explicitly invited.
 * There is no platform-staff bypass here — an operator has no business looking at a
 * customer's living room, and the absence of that branch is the point rather than an
 * omission.
 *
 * Note that {@see AppServiceProvider} registers a `Gate::before`
 * super-admin bypass. That is deliberate for operational tables and wrong for this
 * one, so {@see denyPlatformStaff()} is documented on each ability rather than left
 * implicit — see the class-level note in the test suite.
 */
final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        // Everyone may list *their own* projects; the query scopes by visibility, so
        // this only gates access to the endpoint.
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user) || $project->membershipFor($user) !== null;
    }

    public function create(User $user): bool
    {
        // A verified account is enough. Designing a room is the product; putting a
        // hurdle in front of it would be putting a hurdle in front of the product.
        return true;
    }

    /** Owner or an active editor, and only while the project is not archived. */
    public function update(User $user, Project $project): bool
    {
        return $project->isEditableBy($user);
    }

    /**
     * Deleting is the owner's alone.
     *
     * An editor who can delete the project is an editor who can delete somebody else's
     * work by accident, and there is no undo a customer would trust.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    /** Archiving and reopening travel with editing; deleting does not. */
    public function setStatus(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user)
            || $project->membershipFor($user)?->role->canEdit() === true;
    }

    /**
     * Inviting stays with the owner.
     *
     * A shared account that can give itself away is not shared, it is lost: an editor
     * who can invite can invite anybody, including somebody the owner would not have.
     */
    public function invite(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function removeMember(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }
}

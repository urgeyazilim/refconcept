<?php

declare(strict_types=1);

namespace App\Domains\Ai\Policies;

use App\Domains\Ai\Models\AiTaskRoute;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\AccessControl;

/**
 * Who may change how AI behaves.
 *
 * One policy for the whole configuration surface — providers, models, prompts, routes —
 * because they are one decision wearing four hats. Somebody who can point a task at a
 * different model can already change what customers get; splitting the permission would
 * create a role that can do the dangerous half and not the harmless one.
 *
 * The dangerous half is worth naming. Editing a route changes what every customer's
 * next render costs and how good it is, and pausing one takes a feature off the site
 * for everybody. That is the same weight as a system setting, so it is gated on the
 * same permission rather than on a new one nobody would remember to grant carefully.
 *
 * Reading is separated because it genuinely is less dangerous: an analyst working out
 * why last week cost twice as much needs the routes and the usage, and giving them the
 * ability to edit in order to read would be the larger risk.
 */
final class AiTaskRoutePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, AiTaskRoute $route): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return $this->canConfigure($user);
    }

    public function update(User $user, AiTaskRoute $route): bool
    {
        return $this->canConfigure($user);
    }

    /**
     * The kill switch.
     *
     * Deliberately the same permission as an edit rather than a lower one. It is
     * tempting to let more people stop something than start it — but a pause takes a
     * paid feature away from every customer at once, and "anybody on call can turn AI
     * off" is a decision that wants the same person who could turn it back on.
     */
    public function pause(User $user, AiTaskRoute $route): bool
    {
        return $this->canConfigure($user);
    }

    public function delete(User $user, AiTaskRoute $route): bool
    {
        return $this->canConfigure($user);
    }

    private function canConfigure(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::SystemSettingsManage);
    }

    private function canRead(User $user): bool
    {
        return $this->access->hasPermission($user, Permission::SystemSettingsManage)
            || $this->access->hasPermission($user, Permission::AuditView);
    }
}

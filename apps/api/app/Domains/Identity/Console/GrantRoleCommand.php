<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Enums\SystemRole;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserRole;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Console\Command;

/**
 * Grants a platform or organization role from the console.
 *
 * There is deliberately no HTTP endpoint that hands out platform roles — that would
 * be a privilege-escalation surface reachable from the internet. Bootstrapping the
 * first operator, and any break-glass grant afterwards, happens here, where it
 * requires shell access to the server and still lands in the audit trail.
 */
final class GrantRoleCommand extends Command
{
    protected $signature = 'refconcept:grant-role
        {email : The account to grant the role to}
        {role : Role slug, e.g. super-admin, operator, analyst, seller-owner}
        {--organization= : Organization slug or id, required for organization-scoped roles}
        {--expires= : Optional expiry, e.g. "+30 days"}';

    protected $description = 'Grant a platform or organization role to a user';

    public function handle(AuditLogger $audit): int
    {
        $email = (string) $this->argument('email');
        $slug = (string) $this->argument('role');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account found for {$email}.");

            return self::FAILURE;
        }

        $systemRole = SystemRole::tryFrom($slug);

        if ($systemRole === null) {
            $this->error("Unknown role '{$slug}'. Known roles: ".implode(', ', array_column(SystemRole::cases(), 'value')));

            return self::FAILURE;
        }

        $role = Role::query()
            ->where('slug', $systemRole->value)
            ->where('scope', $systemRole->scope()->value)
            ->first();

        if ($role === null) {
            $this->error('Roles are not seeded. Run: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $organizationId = null;

        if ($systemRole->scope()->value === 'organization') {
            $reference = $this->option('organization');

            if ($reference === null) {
                $this->error("'{$slug}' is organization-scoped; pass --organization=<slug|id>.");

                return self::FAILURE;
            }

            $organization = Organization::query()
                ->where('slug', $reference)
                ->orWhere('id', $reference)
                ->first();

            if ($organization === null) {
                $this->error("No organization found for '{$reference}'.");

                return self::FAILURE;
            }

            $organizationId = (string) $organization->getKey();
        }

        $expiresAt = $this->option('expires') === null ? null : now()->parse((string) $this->option('expires'));

        $grant = UserRole::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'role_id' => $role->getKey(),
                'organization_id' => $organizationId,
            ],
            [
                'granted_at' => now(),
                'expires_at' => $expiresAt,
            ],
        );

        $audit->record(
            action: 'identity.role.granted',
            subject: $user,
            context: [
                'role' => $systemRole->value,
                'organization_id' => $organizationId,
                'expires_at' => $expiresAt?->toIso8601String(),
                'via' => 'console',
            ],
            reason: 'Granted from the console.',
            actorType: 'console',
            organizationId: $organizationId,
        );

        $this->info(sprintf(
            'Granted %s to %s%s.',
            $systemRole->value,
            $email,
            $organizationId === null ? '' : " in organization {$organizationId}",
        ));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\DTOs\RegistrationData;
use App\Domains\Identity\Enums\UserStatus;
use App\Domains\Identity\Models\Consent;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserProfile;
use App\Domains\Identity\Services\EmailVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a customer account.
 *
 * Registration never grants seller rights: those come only from an approved seller
 * application in Phase 2. A fresh account starts as `pending_verification` and holds
 * no roles at all.
 */
final class RegisterUser
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(RegistrationData $data): User
    {
        // The whole registration is one transaction: an account without its consent
        // records is a KVKK problem, and a profile without its account is orphaned.
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'email' => $data->email,
                'phone' => $data->phone,
                'status' => UserStatus::PendingVerification,
                'locale' => $data->locale,
                'timezone' => $data->timezone,
            ]);

            // Assigned outside create() because password_hash is not fillable —
            // nothing arriving from a request should ever be able to set it.
            $user->password_hash = Hash::make($data->password);
            $user->save();

            UserProfile::query()->create([
                'user_id' => $user->getKey(),
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'display_name' => $this->buildDisplayName($data),
                'marketing_opt_in' => $data->marketingOptIn,
            ]);

            foreach ($data->consents as $consent) {
                Consent::query()->create([
                    'user_id' => $user->getKey(),
                    'type' => $consent->type,
                    'version' => $consent->version,
                    'granted' => $consent->granted,
                    'recorded_at' => now(),
                    'ip_address' => $data->ipAddress,
                    'user_agent' => $data->userAgent,
                ]);
            }

            return $user;
        });

        // Outside the transaction: sending mail is a side effect on an external system
        // and must not be able to roll back a committed account, nor be re-sent by a retry.
        $this->verification->issue($user, $data->ipAddress);

        $this->audit->record(
            action: 'identity.user.registered',
            subject: $user,
            context: [
                'locale' => $data->locale,
                'consents' => array_map(
                    static fn ($consent): array => [
                        'type' => $consent->type->value,
                        'version' => $consent->version,
                        'granted' => $consent->granted,
                    ],
                    $data->consents,
                ),
            ],
            actorType: 'system',
        );

        return $user->fresh(['profile']);
    }

    private function buildDisplayName(RegistrationData $data): ?string
    {
        $name = trim(($data->firstName ?? '').' '.($data->lastName ?? ''));

        return $name !== '' ? $name : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domains\Identity\Enums;

/**
 * Lifecycle of an account. Mirrored by a CHECK constraint on users.status.
 */
enum UserStatus: string
{
    /** Registered but has not proven ownership of the e-mail address yet. */
    case PendingVerification = 'pending_verification';

    case Active = 'active';

    /** Temporarily blocked by an operator; can be restored. */
    case Suspended = 'suspended';

    /** Permanently blocked. */
    case Banned = 'banned';

    /**
     * Whether the account may obtain a token and use the API.
     *
     * Unverified accounts can authenticate: they need a session to resend the
     * verification e-mail and complete onboarding. What they cannot do is act —
     * that is gated per-endpoint by the `verified` middleware.
     */
    public function canAuthenticate(): bool
    {
        return match ($this) {
            self::PendingVerification, self::Active => true,
            self::Suspended, self::Banned => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingVerification => 'Doğrulama bekliyor',
            self::Active => 'Aktif',
            self::Suspended => 'Askıya alındı',
            self::Banned => 'Engellendi',
        };
    }
}

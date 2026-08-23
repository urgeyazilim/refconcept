<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A login attempt that did not succeed.
 *
 * The public message is deliberately identical for "no such account" and "wrong
 * password": distinguishing them turns the login form into an account-enumeration
 * oracle. The specific reason is recorded in `login_attempts` for forensics.
 */
final class AuthenticationFailed extends RuntimeException
{
    public const REASON_INVALID_CREDENTIALS = 'invalid_credentials';

    public const REASON_ACCOUNT_SUSPENDED = 'account_suspended';

    public const REASON_ACCOUNT_BANNED = 'account_banned';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Authentication failed: '.$reason);
    }

    public static function invalidCredentials(): self
    {
        return new self(self::REASON_INVALID_CREDENTIALS);
    }

    public static function accountSuspended(): self
    {
        return new self(self::REASON_ACCOUNT_SUSPENDED);
    }

    public static function accountBanned(): self
    {
        return new self(self::REASON_ACCOUNT_BANNED);
    }

    /**
     * Converts to the HTTP-facing error. Blocked accounts get their own message
     * because the user genuinely cannot fix that by retyping a password.
     */
    public function toValidationException(): ValidationException
    {
        $message = match ($this->reason) {
            self::REASON_ACCOUNT_SUSPENDED => 'Hesabınız askıya alınmıştır. Lütfen destek ile iletişime geçin.',
            self::REASON_ACCOUNT_BANNED => 'Bu hesap kalıcı olarak engellenmiştir.',
            default => 'E-posta adresi veya parola hatalı.',
        };

        return ValidationException::withMessages(['email' => [$message]]);
    }
}

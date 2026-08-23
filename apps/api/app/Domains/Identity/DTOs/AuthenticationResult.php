<?php

declare(strict_types=1);

namespace App\Domains\Identity\DTOs;

use App\Domains\Identity\Models\User;
use App\Domains\Identity\Models\UserSession;
use Illuminate\Support\Carbon;

/**
 * The outcome of a successful authentication.
 *
 * The plaintext token exists only here and in the HTTP response — it is never stored,
 * logged or re-derivable from the database.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public User $user,
        public string $token,
        public ?Carbon $expiresAt,
        public UserSession $session,
    ) {}
}

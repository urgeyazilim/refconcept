<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Illuminate\Validation\Rules\Password;

/**
 * One definition of "an acceptable password", used by registration, reset and any
 * future password change. Duplicating these rules per endpoint is how a weaker path
 * quietly appears.
 *
 * The breach check (`uncompromised`) is required by the security rules but calls the
 * Have I Been Pwned k-anonymity API, so it is configuration-driven: enabled in real
 * environments, disabled in tests that must not depend on the network.
 */
final class PasswordRules
{
    /**
     * @return array<int, mixed>
     */
    public static function forNewPassword(): array
    {
        return ['required', 'string', 'confirmed', self::password()];
    }

    public static function password(): Password
    {
        $rule = Password::min((int) config('refconcept.security.password.min_length', 12))
            ->letters()
            ->mixedCase()
            ->numbers();

        if ((bool) config('refconcept.security.password.check_compromised', true)) {
            $rule = $rule->uncompromised();
        }

        return $rule;
    }
}

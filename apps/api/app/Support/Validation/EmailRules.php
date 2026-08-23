<?php

declare(strict_types=1);

namespace App\Support\Validation;

/**
 * One definition of "an acceptable e-mail address".
 *
 * The DNS check (an MX lookup on the domain) is valuable at registration — it stops
 * typos and throwaway domains — but it is a live network call, so it is configuration
 * driven and switched off in the test environment.
 */
final class EmailRules
{
    /**
     * @return array<int, string>
     */
    public static function forRegistration(): array
    {
        $validator = (bool) config('refconcept.security.email.dns_check', true)
            ? 'email:rfc,dns'
            : 'email:rfc';

        return ['required', 'string', $validator, 'max:255'];
    }

    /**
     * Sign-in and password reset accept any syntactically valid address: the account
     * either exists or it does not, and an MX lookup on every attempt would add a
     * network round trip to a rate-limited endpoint.
     *
     * @return array<int, string>
     */
    public static function forLookup(): array
    {
        return ['required', 'string', 'email:rfc', 'max:255'];
    }
}

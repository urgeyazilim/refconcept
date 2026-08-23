<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    | RefConcept is a headless API consumed by three separate Nuxt applications, so
    | the default guard is Sanctum's token guard rather than a session cookie.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'sanctum'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    | `sanctum` authenticates API requests by bearer token.
    |
    | `web` is kept for the few places Laravel still expects a session guard
    | (queue workers resolving a user, console commands, Sanctum's own SPA mode).
    */

    'guards' => [
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    | The model lives in the Identity domain, not app/Models.
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    | RefConcept does not use Laravel's password broker: resets are handled by
    | App\Domains\Identity\Services\PasswordResetService, which stores only hashed
    | tokens and revokes every session on redemption. This block stays so framework
    | code that resolves the config does not fail, but it is not the reset path.
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

];

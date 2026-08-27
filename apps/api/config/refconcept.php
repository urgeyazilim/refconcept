<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    | The brand is RefConcept. The legacy name "RefOne" must never appear in
    | code, configuration, UI copy or documents (20_BRAND_RENAME_CHECKLIST.md).
    */

    'version' => env('REFCONCEPT_VERSION', '1.0.0-web'),

    'milestone' => env('REFCONCEPT_MILESTONE', 'WEB'),

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    | Every monetary amount is stored as an integer in minor units. Floats are
    | forbidden for financial values (06_SECURITY_PAYMENT_FINANCE_RULES.md).
    */

    'money' => [
        'default_currency' => env('REFCONCEPT_DEFAULT_CURRENCY', 'TRY'),
        'supported_currencies' => ['TRY'],
        'minor_unit_scale' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Front-end origins
    |--------------------------------------------------------------------------
    | Used for CORS, signed links, e-mail callbacks and redirect targets.
    */

    'urls' => [
        'storefront' => env('REFCONCEPT_STOREFRONT_URL', 'http://localhost:3000'),
        'seller_portal' => env('REFCONCEPT_SELLER_PORTAL_URL', 'http://localhost:3001'),
        'admin_panel' => env('REFCONCEPT_ADMIN_PANEL_URL', 'http://localhost:3002'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [

        'password' => [
            'min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),

            /*
             * Checks the candidate password against the Have I Been Pwned k-anonymity
             * API. Required by 11_...: "breached/common password policy". Disabled in
             * testing because the suite must not depend on an external network call.
             */
            'check_compromised' => (bool) env('AUTH_PASSWORD_CHECK_COMPROMISED', true),
        ],

        'email' => [
            /*
             * MX lookup on the address domain at registration. A live network call, so
             * it is disabled in the test environment (see phpunit.xml).
             */
            'dns_check' => (bool) env('AUTH_EMAIL_DNS_CHECK', true),
        ],

        'tokens' => [
            'ttl_days' => (int) env('AUTH_TOKEN_TTL_DAYS', 30),
        ],

        'email_verification' => [
            'ttl_minutes' => (int) env('AUTH_EMAIL_VERIFICATION_TTL_MINUTES', 1440),
        ],

        'password_reset' => [
            'ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TTL_MINUTES', 60),
        ],

        /*
         * Rate limits, expressed as "attempts per minute" per throttle key. Login and
         * password reset are keyed by e-mail *and* IP so one attacker cannot lock out a
         * victim's account simply by failing their login repeatedly.
         */
        'rate_limits' => [
            'login' => (int) env('AUTH_RATE_LIMIT_LOGIN', 5),
            'register' => (int) env('AUTH_RATE_LIMIT_REGISTER', 5),
            'password_reset' => (int) env('AUTH_RATE_LIMIT_PASSWORD_RESET', 3),
            'verification_resend' => (int) env('AUTH_RATE_LIMIT_VERIFICATION_RESEND', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | Seller documents, room photographs and AI outputs are private by default.
    | Nothing customer-uploaded is ever placed on a publicly addressable disk.
    */

    'storage' => [
        'private_disk' => env('REFCONCEPT_PRIVATE_DISK', 's3'),
        'public_disk' => env('REFCONCEPT_PUBLIC_DISK', 's3-public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Foreign exchange
    |--------------------------------------------------------------------------
    | The platform reports in lira. Everything a customer, a seller or an
    | operator ever sees is TRY — see money.supported_currencies above.
    |
    | AI providers are the one thing that does not cooperate: Google publishes
    | its price list in dollars per million tokens, so a cost arrives quoted in
    | USD and has to be turned into lira before it is stored. The alternative —
    | keeping dollars in the database and putting a lira sign on them — would
    | show an operator a number that is wrong by whatever the rate happens to be.
    |
    | A configured rate rather than a live feed, deliberately. A cost recorded
    | today must not change tomorrow because the market moved: the figure is what
    | the spend was worth when it happened. Operators update it from
    | Sistem → Ayarlar (`finance.usd_try_rate`) when it drifts far enough to
    | matter.
    */

    'fx' => [
        'usd_try' => (float) env('REFCONCEPT_USD_TRY_RATE', 34.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Commission
    |--------------------------------------------------------------------------
    | Basis points, never percentages: 1200 bps = 12%. The resolver hierarchy in
    | 06_SECURITY_PAYMENT_FINANCE_RULES.md falls back to this platform default.
    */

    'commission' => [
        'platform_default_bps' => (int) env('REFCONCEPT_DEFAULT_COMMISSION_BPS', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settlement
    |--------------------------------------------------------------------------
    | How long a seller's money is held after delivery before it can be paid
    | out. The return window plus a margin: paying before it closes means
    | chasing a seller for money they have already spent. Configured rather
    | than constant because it is a commercial decision that changes.
    */

    'settlement' => [
        'hold_days' => (int) env('REFCONCEPT_SETTLEMENT_HOLD_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Returns
    |--------------------------------------------------------------------------
    | How long after delivery a customer may still ask to send something back.
    | Fourteen days is the Turkish distance-selling right; the settlement hold
    | matches it deliberately, because paying a seller before the window closes
    | means chasing money from somebody who has already spent it.
    */

    'returns' => [
        'window_days' => (int) env('REFCONCEPT_RETURN_WINDOW_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal document versions
    |--------------------------------------------------------------------------
    | The version a registration must accept. Bumping these forces re-acceptance
    | and is recorded per user in the `consents` table.
    */

    'legal' => [
        'privacy_notice_version' => env('REFCONCEPT_PRIVACY_VERSION', '2026-01'),
        'terms_version' => env('REFCONCEPT_TERMS_VERSION', '2026-01'),
    ],

];

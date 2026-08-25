<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
| Which providers exist, which one is used by default, and the timings that
| decide when an unfinished payment stops being someone's problem.
|
| Credentials are read from the environment and never committed. A provider
| with no credentials is simply not enabled — which is why `fake` is the only
| one on by default: a fresh checkout must not silently point at a real bank.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    | Named rather than resolved by "the first one configured", so a
    | half-finished iyzico credential in an environment file cannot quietly
    | become the gateway that takes real money.
    */

    'default' => env('REFCONCEPT_PAYMENT_GATEWAY', 'fake'),

    'gateways' => [

        /*
         * The in-house test provider.
         *
         * Real enough to exercise every path — 3DS, decline, timeout, webhook,
         * refund — without a network call, which is what lets the payment tests
         * be part of the ordinary suite instead of a thing run by hand against
         * a sandbox once a release.
         *
         * The outcome is chosen by the card token the client sends, so a test
         * asks for the failure it wants instead of the suite depending on which
         * amount happens to be unlucky.
         */
        'fake' => [
            'enabled' => (bool) env('REFCONCEPT_PAYMENT_FAKE_ENABLED', true),

            /*
             * Signs and verifies the fake provider's webhooks. Defaulted so the
             * development stack works out of the box; an environment that takes
             * real money does not have the fake gateway enabled at all, so the
             * default secret can never protect anything that matters.
             */
            'webhook_secret' => env('REFCONCEPT_PAYMENT_FAKE_SECRET', 'refconcept-fake-gateway'),
        ],

        // Phase 12. Present so the shape is visible, empty so it cannot be selected.
        'iyzico' => [
            'enabled' => (bool) env('REFCONCEPT_IYZICO_ENABLED', false),
            'api_key' => env('REFCONCEPT_IYZICO_API_KEY'),
            'secret_key' => env('REFCONCEPT_IYZICO_SECRET_KEY'),
            'base_uri' => env('REFCONCEPT_IYZICO_BASE_URI', 'https://sandbox-api.iyzipay.com'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timings
    |--------------------------------------------------------------------------
    */

    'timings' => [
        /*
         * How long a checkout session lives.
         *
         * Deliberately the same fifteen minutes as the stock hold taken by
         * CartService. A session that outlived its hold would be a customer
         * paying for stock we had already given to somebody else; a hold that
         * outlived its session would keep goods off the market for a checkout
         * nobody is going to finish.
         */
        'session_ttl_seconds' => (int) env('REFCONCEPT_CHECKOUT_TTL', 900),

        /*
         * How long a started payment may stay unfinished before it is treated
         * as abandoned. Longer than the session, because a customer who is on
         * their bank's 3DS page has left our clock behind and coming back to
         * "your payment expired" while the bank thinks it succeeded is the one
         * outcome worse than making them wait.
         */
        'intent_ttl_seconds' => (int) env('REFCONCEPT_PAYMENT_TTL', 1800),

        /*
         * How long an idempotency key is honoured. A day covers every honest
         * retry — a flaky mobile connection, an app resumed from the
         * background — without keeping request bodies around indefinitely.
         */
        'idempotency_ttl_seconds' => (int) env('REFCONCEPT_IDEMPOTENCY_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        /*
         * Refuse a body larger than this outright.
         *
         * A webhook endpoint is unauthenticated by nature: anybody who knows
         * the URL can post to it, and parsing a hundred megabytes of JSON
         * before deciding it was not signed is a denial of service with extra
         * steps.
         */
        'max_body_bytes' => (int) env('REFCONCEPT_WEBHOOK_MAX_BYTES', 262144),

        /*
         * How many times a failed event is retried before a human is needed.
         * Payment events are worth retrying persistently — the alternative to a
         * retry is a customer who paid and has nothing to show for it.
         */
        'max_attempts' => (int) env('REFCONCEPT_WEBHOOK_MAX_ATTEMPTS', 8),
    ],

];

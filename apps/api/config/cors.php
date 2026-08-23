<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
| The API is consumed by three first-party browser applications on their own
| origins. Origins are explicit — never `*` — because authenticated requests
| carry cookies for the SPA session guard added in Phase 1.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('REFCONCEPT_STOREFRONT_URL', 'http://localhost:3000'),
        env('REFCONCEPT_SELLER_PORTAL_URL', 'http://localhost:3001'),
        env('REFCONCEPT_ADMIN_PANEL_URL', 'http://localhost:3002'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    'supports_credentials' => true,

];

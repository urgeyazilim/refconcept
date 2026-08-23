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

    'version' => env('REFCONCEPT_VERSION', '0.1.0-phase0'),

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

];

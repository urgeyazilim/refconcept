<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The API is headless: the customer storefront, seller portal and super admin
| are separate Nuxt applications. This root route exists only so a browser
| hitting the API host gets a useful pointer instead of a 404.
*/

Route::get('/', fn (): JsonResponse => response()->json([
    'application' => config('app.name'),
    'documentation' => '/api/documentation',
    'health' => '/api/health',
]));

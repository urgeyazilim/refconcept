<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RefConcept API routes
|--------------------------------------------------------------------------
| Platform endpoints stay unversioned because infrastructure depends on their
| paths. Everything else lives under /api/v1 and is grouped per domain, with
| each domain owning its own route file.
*/

Route::get('/health', HealthController::class)->name('health');

Route::prefix('v1')->as('v1.')->group(function (): void {
    require __DIR__.'/domains/identity.php';
    require __DIR__.'/domains/sellers.php';
    require __DIR__.'/domains/catalog.php';
    require __DIR__.'/domains/commerce.php';
    require __DIR__.'/domains/projects.php';
    require __DIR__.'/domains/ai.php';
    require __DIR__.'/domains/credits.php';
    require __DIR__.'/domains/shopping.php';
    require __DIR__.'/domains/payments.php';
    require __DIR__.'/domains/orders.php';
});

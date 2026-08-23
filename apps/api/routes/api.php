<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RefConcept API routes
|--------------------------------------------------------------------------
| Domain route files are registered here as phases land. Everything is
| versioned under /api/v1 except the unversioned platform endpoints below,
| which infrastructure depends on and must never move.
*/

Route::get('/health', HealthController::class)->name('health');

Route::prefix('v1')->as('v1.')->group(function (): void {
    // Phase 1+ domain routes are attached here.
});

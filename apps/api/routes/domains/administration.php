<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAnalyticsController;
use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminOrderController;
use App\Domains\Administration\Http\Controllers\AdminSystemController;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform administration
|--------------------------------------------------------------------------
| No permission checks appear here or in these controllers. Every route under
| /admin passes through the matrix middleware, which is the single authority
| on who may call what — and which refuses anything the matrix does not
| claim, so an endpoint added without a decision is closed rather than open.
|
| See AdminPermissionMatrix; the suite fails if any admin route is missing
| from it.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {

        Route::prefix('orders')->as('orders.')->group(function (): void {
            Route::get('/', [AdminOrderController::class, 'index'])->name('index');
            Route::get('{orderNumber}', [AdminOrderController::class, 'show'])->name('show');
        });

        Route::prefix('audit')->as('audit.')->group(function (): void {
            Route::get('/', [AdminAuditController::class, 'index'])->name('index');
            Route::get('matrix', [AdminAuditController::class, 'matrix'])->name('matrix');
        });

        Route::prefix('analytics')->as('analytics.')->group(function (): void {
            Route::get('overview', [AdminAnalyticsController::class, 'overview'])->name('overview');
            Route::get('orders', [AdminAnalyticsController::class, 'orderSeries'])->name('orders');
            Route::get('catalogue-coverage', [AdminAnalyticsController::class, 'catalogueCoverage'])
                ->name('catalogue-coverage');
        });

        Route::prefix('system')->as('system.')->group(function (): void {
            Route::get('flags', [AdminSystemController::class, 'flags'])->name('flags.index');
            Route::post('flags', [AdminSystemController::class, 'saveFlag'])->name('flags.store');
            Route::patch('flags/{flag}', [AdminSystemController::class, 'saveFlag'])->name('flags.update');

            Route::get('settings', [AdminSystemController::class, 'settings'])->name('settings.index');
            Route::patch('settings/{setting}', [AdminSystemController::class, 'saveSetting'])
                ->name('settings.update');

            Route::get('jobs', [AdminSystemController::class, 'jobs'])->name('jobs.index');
            Route::post('webhooks/{event}/replay', [AdminSystemController::class, 'replayWebhook'])
                ->name('webhooks.replay');
        });
    });

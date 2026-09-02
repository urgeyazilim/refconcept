<?php

declare(strict_types=1);

use App\Domains\Administration\Http\Controllers\AdminAnalyticsController;
use App\Domains\Administration\Http\Controllers\AdminAuditController;
use App\Domains\Administration\Http\Controllers\AdminCustomerController;
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

        /*
         * Customers, for support.
         *
         * Read-only throughout. `media` is the one endpoint that hands back anything of the
         * customer's own — a room photograph or a render — and it is a POST rather than a
         * GET on purpose: it writes an audit entry naming the operator and requires a
         * written reason, neither of which belongs in a URL somebody can bookmark or share.
         */
        Route::prefix('customers')->as('customers.')->group(function (): void {
            Route::get('/', [AdminCustomerController::class, 'index'])->name('index');
            Route::get('{customer}', [AdminCustomerController::class, 'show'])->name('show');
            Route::post('{customer}/media/{media}', [AdminCustomerController::class, 'media'])->name('media');
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

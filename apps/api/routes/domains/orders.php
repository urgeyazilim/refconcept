<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Orders\Http\Controllers\OrderController;
use App\Domains\Orders\Http\Controllers\SellerOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
| Addressed by number rather than id, on both sides: it is what a customer
| has in an e-mail and what a seller reads off a picking list. Every route
| checks the number against the caller before saying anything, and answers
| 404 when it does not match — whether somebody else's order exists is not a
| thing to confirm to a stranger, or to a competitor.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {

    Route::prefix('orders')->as('orders.')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::get('{orderNumber}', [OrderController::class, 'show'])->name('show');
    });

    Route::prefix('seller/orders')->as('seller.orders.')->group(function (): void {
        Route::get('/', [SellerOrderController::class, 'index'])->name('index');
        Route::get('{number}', [SellerOrderController::class, 'show'])->name('show');

        // One endpoint for every transition: which moves are legal is the status
        // machine's business, and splitting it across verbs would put half the rules in
        // the routing table.
        Route::post('{number}/status', [SellerOrderController::class, 'advance'])->name('advance');
    });
});

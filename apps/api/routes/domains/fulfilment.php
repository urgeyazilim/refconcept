<?php

declare(strict_types=1);

use App\Domains\Fulfilment\Http\Controllers\AdminRefundController;
use App\Domains\Fulfilment\Http\Controllers\ReturnController;
use App\Domains\Fulfilment\Http\Controllers\SellerFulfilmentController;
use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shipping, returns and refunds
|--------------------------------------------------------------------------
| Returns are addressed by reference and checked against the caller on both
| sides, answering 404 when they do not match — whether somebody else's
| return exists is not a thing to confirm to a stranger or a competitor.
|
| Refunds are finance's, behind the settle permission rather than the read
| one: sending money back is still sending money.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {

    Route::get('returns/reasons', [ReturnController::class, 'reasons'])->name('returns.reasons');

    Route::middleware(EnsureEmailIsVerified::class)
        ->prefix('returns')
        ->as('returns.')
        ->group(function (): void {
            Route::get('/', [ReturnController::class, 'index'])->name('index');
            Route::post('/', [ReturnController::class, 'store'])->name('store');
            Route::get('{reference}', [ReturnController::class, 'show'])->name('show');
            Route::post('{reference}/sent', [ReturnController::class, 'markSent'])->name('sent');
            Route::delete('{reference}', [ReturnController::class, 'cancel'])->name('cancel');
        });

    Route::prefix('seller')->as('seller.')->group(function (): void {
        Route::get('orders/{number}/shipments', [SellerFulfilmentController::class, 'shipments'])
            ->name('shipments.index');
        Route::post('orders/{number}/shipments', [SellerFulfilmentController::class, 'ship'])
            ->name('shipments.store');
        Route::post('orders/{number}/shipments/{shipment}/delivered', [SellerFulfilmentController::class, 'markDelivered'])
            ->name('shipments.delivered');

        Route::get('returns', [SellerFulfilmentController::class, 'returns'])->name('returns.index');
        Route::post('returns/{reference}/decision', [SellerFulfilmentController::class, 'decideReturn'])
            ->name('returns.decide');
        Route::post('returns/{reference}/status', [SellerFulfilmentController::class, 'advanceReturn'])
            ->name('returns.advance');
    });

    Route::prefix('admin/refunds')->as('admin.refunds.')->group(function (): void {
        Route::get('/', [AdminRefundController::class, 'index'])->name('index');
        Route::post('/', [AdminRefundController::class, 'store'])->name('store');
        Route::post('{refund}/retry', [AdminRefundController::class, 'retry'])->name('retry');
        Route::get('orders/{orderNumber}', [AdminRefundController::class, 'refundable'])->name('refundable');
    });
});

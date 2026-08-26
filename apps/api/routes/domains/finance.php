<?php

declare(strict_types=1);

use App\Domains\Finance\Http\Controllers\AdminFinanceController;
use App\Domains\Finance\Http\Controllers\SellerEarningsController;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Finance
|--------------------------------------------------------------------------
| A seller's routes carry no id: /seller/earnings is always *their* money,
| which is the strongest form the ownership rule can take.
|
| The finance routes keep the two permissions Phase 14 introduced. Reading
| the books is a support job; approving a payout commits money and marking
| one paid records that it left, and neither should be reachable by somebody
| answering "where is my money".
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {

    Route::prefix('seller/earnings')->as('seller.earnings.')->group(function (): void {
        Route::get('/', [SellerEarningsController::class, 'summary'])->name('summary');
        Route::get('orders', [SellerEarningsController::class, 'orders'])->name('orders');
        Route::get('settlements', [SellerEarningsController::class, 'settlements'])->name('settlements');
    });

    Route::prefix('admin/finance')->as('admin.finance.')->group(function (): void {
        Route::get('overview', [AdminFinanceController::class, 'overview'])->name('overview');
        Route::get('entries', [AdminFinanceController::class, 'entries'])->name('entries');

        Route::get('settlements', [AdminFinanceController::class, 'settlements'])->name('settlements.index');
        Route::post('settlements/build', [AdminFinanceController::class, 'buildSettlements'])
            ->name('settlements.build');
        Route::post('settlements/{settlement}/approve', [AdminFinanceController::class, 'approve'])
            ->name('settlements.approve');
        Route::post('settlements/{settlement}/paid', [AdminFinanceController::class, 'markPaid'])
            ->name('settlements.paid');
        Route::post('settlements/{settlement}/cancel', [AdminFinanceController::class, 'cancel'])
            ->name('settlements.cancel');

        Route::get('commission-rules', [AdminFinanceController::class, 'commissionRules'])
            ->name('commission.index');
        Route::post('commission-rules', [AdminFinanceController::class, 'saveCommissionRule'])
            ->name('commission.store');
        Route::patch('commission-rules/{rule}', [AdminFinanceController::class, 'saveCommissionRule'])
            ->name('commission.update');

        Route::get('sellers/{seller}/balance', [AdminFinanceController::class, 'sellerBalance'])
            ->name('sellers.balance');
    });
});

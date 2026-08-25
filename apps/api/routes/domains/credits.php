<?php

declare(strict_types=1);

use App\Domains\Credits\Http\Controllers\AdminCreditController;
use App\Domains\Credits\Http\Controllers\CreditWalletController;
use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Credits
|--------------------------------------------------------------------------
| The customer routes carry no id at all: /credits is always *your* wallet.
| That is the strongest form the ownership rule can take — a forgotten policy
| check cannot expose somebody else's balance when there is no way to name
| somebody else.
|
| Redeeming a code requires a verified e-mail. Without that, a promotion is a
| free-credit machine for anybody willing to type a different address each time.
|
| /admin/credits is gated on the same permission as system settings, because
| adjusting a balance by hand is indistinguishable from theft without a record
| of who did it and why.
*/

Route::prefix('credits')->as('credits.')->group(function (): void {

    // What is on sale is public: a pricing page should render for somebody who has
    // not signed in yet, which is exactly when they are deciding whether to.
    Route::get('packages', [CreditWalletController::class, 'packages'])->name('packages');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
        Route::get('/', [CreditWalletController::class, 'show'])->name('show');
        Route::get('transactions', [CreditWalletController::class, 'transactions'])->name('transactions');

        Route::middleware(EnsureEmailIsVerified::class)
            ->post('redeem', [CreditWalletController::class, 'redeem'])
            ->name('redeem');
    });
});

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('admin/credits')
    ->as('admin.credits.')
    ->group(function (): void {

        Route::get('packages', [AdminCreditController::class, 'packages'])->name('packages.index');
        Route::post('packages', [AdminCreditController::class, 'savePackage'])->name('packages.store');
        Route::patch('packages/{package}', [AdminCreditController::class, 'savePackage'])->name('packages.update');

        Route::get('promotions', [AdminCreditController::class, 'promotions'])->name('promotions.index');
        Route::post('promotions', [AdminCreditController::class, 'savePromotion'])->name('promotions.store');
        Route::patch('promotions/{promotion}', [AdminCreditController::class, 'savePromotion'])
            ->name('promotions.update');

        Route::get('wallets/{user}', [AdminCreditController::class, 'wallet'])->name('wallets.show');
        Route::post('wallets/{user}/adjust', [AdminCreditController::class, 'adjust'])->name('wallets.adjust');
    });

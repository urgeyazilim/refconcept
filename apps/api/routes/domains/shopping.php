<?php

declare(strict_types=1);

use App\Domains\Commerce\Http\Controllers\CartController;
use App\Domains\Commerce\Http\Controllers\FavoriteController;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Favourites and the basket
|--------------------------------------------------------------------------
| No cart id appears anywhere: /cart is always *your* cart, which is the
| strongest form the ownership rule can take. The only id in these routes is
| a line's, and it is checked against the caller's own cart before anything
| happens to it.
|
| Favourites are keyed by product for the same reason — a product id and the
| signed-in user identify the row completely.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {

    Route::prefix('favorites')->as('favorites.')->group(function (): void {
        Route::get('/', [FavoriteController::class, 'index'])->name('index');
        // One request for a whole results page, rather than a flag on every product in
        // every catalogue response that anonymous visitors also read.
        Route::post('check', [FavoriteController::class, 'check'])->name('check');
        Route::post('{product}', [FavoriteController::class, 'store'])->name('store');
        Route::delete('{product}', [FavoriteController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('cart')->as('cart.')->group(function (): void {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::post('items', [CartController::class, 'add'])->name('items.add');
        Route::patch('items/{item}', [CartController::class, 'updateItem'])->name('items.update');
        Route::delete('items/{item}', [CartController::class, 'removeItem'])->name('items.remove');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');

        // Accepting a price that moved is an explicit act, so the higher figure is
        // something the customer agreed to rather than something that happened to them.
        Route::post('accept-prices', [CartController::class, 'acceptPrices'])->name('accept-prices');

        Route::post('checkout', [CartController::class, 'beginCheckout'])->name('checkout');
        Route::delete('checkout', [CartController::class, 'abandonCheckout'])->name('checkout.abandon');
    });
});

<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Imports\Http\Controllers\SellerImportController;
use App\Domains\Inventory\Http\Controllers\SellerInventoryController;
use App\Domains\Partners\Http\Controllers\PartnerStockController;
use App\Domains\Partners\Http\Controllers\SellerApiCredentialController;
use App\Domains\Partners\Http\Middleware\AuthenticatePartner;
use App\Domains\Pricing\Http\Controllers\SellerPriceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Import, pricing, inventory and the partner API
|--------------------------------------------------------------------------
| Two audiences with two authentication schemes:
|
|   /seller/*    a signed-in person in the seller portal, via Sanctum.
|
|   /partner/*   a machine, via a scoped key/secret credential. Deliberately not
|                Sanctum: a partner credential belongs to a system rather than to
|                a person, carries its own scopes, and must be revocable without
|                logging anybody out.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('seller')
    ->as('seller.')
    ->group(function (): void {

        // --- bulk import ---------------------------------------------------
        Route::get('imports/template', [SellerImportController::class, 'template'])->name('imports.template');
        Route::get('imports', [SellerImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [SellerImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{batch}', [SellerImportController::class, 'show'])->name('imports.show');
        Route::get('imports/{batch}/rows', [SellerImportController::class, 'rows'])->name('imports.rows');
        Route::patch('imports/{batch}/mapping', [SellerImportController::class, 'updateMapping'])
            ->name('imports.mapping');
        Route::post('imports/{batch}/validate', [SellerImportController::class, 'validateBatch'])
            ->name('imports.validate');
        Route::post('imports/{batch}/commit', [SellerImportController::class, 'commit'])->name('imports.commit');
        Route::delete('imports/{batch}', [SellerImportController::class, 'destroy'])->name('imports.destroy');

        // --- pricing --------------------------------------------------------
        Route::get('prices', [SellerPriceController::class, 'index'])->name('prices.index');
        Route::post('prices/bulk', [SellerPriceController::class, 'bulkUpdate'])->name('prices.bulk');
        Route::get('prices/{sku}/history', [SellerPriceController::class, 'history'])->name('prices.history');

        Route::get('price-lists', [SellerPriceController::class, 'lists'])->name('price-lists.index');
        Route::post('price-lists', [SellerPriceController::class, 'storeList'])->name('price-lists.store');
        Route::post('price-lists/{priceList}/end', [SellerPriceController::class, 'endList'])
            ->name('price-lists.end');

        // --- inventory ------------------------------------------------------
        Route::get('stock', [SellerInventoryController::class, 'index'])->name('stock.index');
        Route::post('stock/adjust', [SellerInventoryController::class, 'adjust'])->name('stock.adjust');
        Route::post('stock/stocktake', [SellerInventoryController::class, 'stocktake'])->name('stock.stocktake');
        Route::get('stock/{stockItem}/movements', [SellerInventoryController::class, 'movements'])
            ->name('stock.movements');

        Route::get('stock-locations', [SellerInventoryController::class, 'locations'])->name('stock-locations.index');
        Route::post('stock-locations', [SellerInventoryController::class, 'storeLocation'])
            ->name('stock-locations.store');

        // --- machine credentials ---------------------------------------------
        Route::get('api-credentials', [SellerApiCredentialController::class, 'index'])
            ->name('api-credentials.index');
        Route::post('api-credentials', [SellerApiCredentialController::class, 'store'])
            ->name('api-credentials.store');
        Route::get('api-credentials/{credential}/usage', [SellerApiCredentialController::class, 'usage'])
            ->name('api-credentials.usage');
        Route::delete('api-credentials/{credential}', [SellerApiCredentialController::class, 'destroy'])
            ->name('api-credentials.destroy');
    });

/*
 * The partner API.
 *
 * Each route names the scope it needs. Stating it per route rather than per group is
 * what lets a warehouse integration hold `stock:write` without also being able to
 * change prices — the reason scopes exist at all.
 */
Route::prefix('partner')->as('partner.')->group(function (): void {
    Route::get('stock', [PartnerStockController::class, 'index'])
        ->middleware(AuthenticatePartner::class.':stock:read')
        ->name('stock.index');

    Route::post('stock', [PartnerStockController::class, 'updateStock'])
        ->middleware(AuthenticatePartner::class.':stock:write')
        ->name('stock.update');

    Route::post('prices', [PartnerStockController::class, 'updatePrices'])
        ->middleware(AuthenticatePartner::class.':prices:write')
        ->name('prices.update');
});

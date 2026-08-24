<?php

declare(strict_types=1);

use App\Domains\Catalog\Http\Controllers\PublicCatalogController;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Products\Http\Controllers\AdminProductModerationController;
use App\Domains\Products\Http\Controllers\ProductMediaController;
use App\Domains\Products\Http\Controllers\SellerProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog and product routes
|--------------------------------------------------------------------------
| Three surfaces with three different audiences:
|
|   /catalog/*        public. No authentication, and every query runs through
|                     Product::publiclyVisible() so a draft can never leak.
|
|   /seller/products  the seller's own listings, scoped to their organizations.
|
|   /admin/products   moderation. Policy-checked on every route.
*/

Route::prefix('catalog')->as('catalog.')->group(function (): void {
    Route::get('categories', [PublicCatalogController::class, 'categories'])->name('categories');
    Route::get('vocabulary', [PublicCatalogController::class, 'vocabulary'])->name('vocabulary');
    Route::get('brands', [PublicCatalogController::class, 'brands'])->name('brands');
    Route::get('categories/{slug}/attributes', [PublicCatalogController::class, 'categoryAttributes'])
        ->name('categories.attributes');
    Route::get('products', [PublicCatalogController::class, 'products'])->name('products');
    Route::get('products/{slug}', [PublicCatalogController::class, 'product'])->name('product');
});

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('seller')
    ->as('seller.')
    ->group(function (): void {

        Route::get('products', [SellerProductController::class, 'index'])->name('products.index');
        Route::post('products', [SellerProductController::class, 'store'])->name('products.store');
        Route::get('products/{product}', [SellerProductController::class, 'show'])->name('products.show');
        Route::patch('products/{product}', [SellerProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [SellerProductController::class, 'destroy'])->name('products.destroy');

        Route::post('products/{product}/submit', [SellerProductController::class, 'submit'])
            ->name('products.submit');
        Route::patch('products/{product}/status', [SellerProductController::class, 'setStatus'])
            ->name('products.status');

        Route::post('products/{product}/skus', [SellerProductController::class, 'storeSku'])
            ->name('products.skus.store');
        Route::patch('products/{product}/skus/{sku}', [SellerProductController::class, 'updateSku'])
            ->name('products.skus.update');
        Route::delete('products/{product}/skus/{sku}', [SellerProductController::class, 'destroySku'])
            ->name('products.skus.destroy');

        // Imagery. Authorised on the product, so a media id alone opens nothing.
        Route::post('products/{product}/media', [ProductMediaController::class, 'store'])
            ->name('products.media.store');
        Route::post('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])
            ->name('products.media.reorder');
        Route::patch('products/{product}/media/{medium}', [ProductMediaController::class, 'update'])
            ->name('products.media.update');
        Route::delete('products/{product}/media/{medium}', [ProductMediaController::class, 'destroy'])
            ->name('products.media.destroy');
    });

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {

        Route::get('products', [AdminProductModerationController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [AdminProductModerationController::class, 'show'])->name('products.show');
        Route::post('products/{product}/review', [AdminProductModerationController::class, 'startReview'])
            ->name('products.review');
        Route::post('products/{product}/approve', [AdminProductModerationController::class, 'approve'])
            ->name('products.approve');
        Route::post('products/{product}/reject', [AdminProductModerationController::class, 'reject'])
            ->name('products.reject');
        Route::post('products/{product}/recall', [AdminProductModerationController::class, 'recall'])
            ->name('products.recall');
    });

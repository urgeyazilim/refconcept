<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Sellers\Http\Controllers\AdminSellerApplicationController;
use App\Domains\Sellers\Http\Controllers\AdminSellerController;
use App\Domains\Sellers\Http\Controllers\SellerAgreementController;
use App\Domains\Sellers\Http\Controllers\SellerApplicationController;
use App\Domains\Sellers\Http\Controllers\SellerDashboardController;
use App\Domains\Sellers\Http\Controllers\SellerDocumentController;
use App\Domains\Sellers\Http\Controllers\SellerTeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller onboarding routes
|--------------------------------------------------------------------------
| Two distinct surfaces:
|
|   /seller/*  the applicant's own file. No id in the path — the application is
|              always resolved from the signed-in user, so one applicant cannot
|              address another's.
|
|   /admin/*   platform review. Ids appear here because an operator legitimately
|              works across applications, and every route is policy-checked.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class, EnsureEmailIsVerified::class])
    ->prefix('seller')
    ->as('seller.')
    ->group(function (): void {

        Route::get('application', [SellerApplicationController::class, 'show'])->name('application.show');
        Route::post('application', [SellerApplicationController::class, 'store'])->name('application.store');
        Route::patch('application', [SellerApplicationController::class, 'update'])->name('application.update');

        Route::put('application/sections/{section}', [SellerApplicationController::class, 'updateSection'])
            ->name('application.section');

        Route::post('application/submit', [SellerApplicationController::class, 'submit'])->name('application.submit');
        Route::post('application/withdraw', [SellerApplicationController::class, 'withdraw'])->name('application.withdraw');

        // The seller's own record. Deliberately not the admin route: that one asks whether
        // the caller holds a platform permission, and this one asks whether the seller is
        // theirs. See AdminSellerController::mine().
        Route::get('profile', [AdminSellerController::class, 'mine'])->name('profile.show');

        // The queue first, then the money: the order a seller actually works a morning in.
        Route::get('dashboard', [SellerDashboardController::class, 'show'])->name('dashboard');

        /*
         * The team. No organization id in the path — it is always resolved from the
         * caller, so one seller cannot address another's team however the request is
         * shaped. Reading needs seller.users.view; changing needs seller.users.manage,
         * which only an owner holds.
         */
        Route::get('team', [SellerTeamController::class, 'index'])->name('team.index');
        Route::post('team', [SellerTeamController::class, 'store'])->name('team.store');
        Route::patch('team/{member}', [SellerTeamController::class, 'update'])->name('team.update');
        Route::delete('team/{member}', [SellerTeamController::class, 'destroy'])->name('team.destroy');

        Route::get('agreements', [SellerAgreementController::class, 'index'])->name('agreements.index');
        Route::post('agreements/{agreement}/accept', [SellerAgreementController::class, 'accept'])
            ->name('agreements.accept');

        Route::post('documents', [SellerDocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/{document}/link', [SellerDocumentController::class, 'link'])->name('documents.link');
        Route::get('documents/{document}/download', [SellerDocumentController::class, 'download'])
            ->name('documents.download');
        Route::delete('documents/{document}', [SellerDocumentController::class, 'destroy'])->name('documents.destroy');
    });

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->prefix('admin')
    ->as('admin.')
    ->group(function (): void {

        Route::get('seller-applications', [AdminSellerApplicationController::class, 'index'])
            ->name('seller-applications.index');
        Route::get('seller-applications/{application}', [AdminSellerApplicationController::class, 'show'])
            ->name('seller-applications.show');
        Route::post('seller-applications/{application}/review', [AdminSellerApplicationController::class, 'startReview'])
            ->name('seller-applications.review');
        Route::post('seller-applications/{application}/approve', [AdminSellerApplicationController::class, 'approve'])
            ->name('seller-applications.approve');
        Route::post('seller-applications/{application}/reject', [AdminSellerApplicationController::class, 'reject'])
            ->name('seller-applications.reject');

        Route::post('seller-documents/{document}/review', [AdminSellerApplicationController::class, 'reviewDocument'])
            ->name('seller-documents.review');

        Route::get('sellers', [AdminSellerController::class, 'index'])->name('sellers.index');
        Route::get('sellers/{seller}', [AdminSellerController::class, 'show'])->name('sellers.show');
        Route::post('sellers/{seller}/suspend', [AdminSellerController::class, 'suspend'])->name('sellers.suspend');
        Route::post('sellers/{seller}/reactivate', [AdminSellerController::class, 'reactivate'])
            ->name('sellers.reactivate');
        Route::patch('sellers/{seller}/commission', [AdminSellerController::class, 'setCommission'])
            ->name('sellers.commission');
    });

<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\AddressController;
use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\ProfileController;
use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity routes
|--------------------------------------------------------------------------
| Public credential endpoints are rate limited by named limiters defined in
| AppServiceProvider. Authenticated routes additionally run EnsureUserIsActive so a
| suspension takes effect immediately rather than when the token eventually expires.
*/

Route::prefix('auth')->as('auth.')->group(function (): void {

    // --- public ---------------------------------------------------------------
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register')
        ->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('login');

    Route::post('email/verify', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:auth-login')
        ->name('email.verify');

    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-password-reset')
        ->name('password.forgot');

    Route::post('password/reset', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-password-reset')
        ->name('password.reset');

    // --- authenticated --------------------------------------------------------
    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');

        Route::post('email/resend', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:auth-verification-resend')
            ->name('email.resend');
    });
});

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function (): void {

    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    /*
     * Addresses require a verified account: they are the first step towards placing
     * an order, and an unverified address book is a spam and fraud surface.
     */
    Route::middleware(EnsureEmailIsVerified::class)->group(function (): void {
        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::get('addresses/{address}', [AddressController::class, 'show'])->name('addresses.show');
        Route::patch('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    });
});

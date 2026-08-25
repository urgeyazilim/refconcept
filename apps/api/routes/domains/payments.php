<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Domains\Identity\Http\Middleware\EnsureUserIsActive;
use App\Domains\Payments\Http\Controllers\CheckoutController;
use App\Domains\Payments\Http\Controllers\FakeGatewayController;
use App\Domains\Payments\Http\Controllers\PaymentWebhookController;
use App\Domains\Payments\Http\Middleware\EnsureIdempotentRequest;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Checkout and payments
|--------------------------------------------------------------------------
| No session id appears in the routes that open or read a checkout: /checkout
| is always *your* live session, which is the same ownership rule the cart
| routes use and the strongest form it can take. The one id that does appear
| is a payment's, on the route that asks what became of it, and it is checked
| against the caller first.
|
| Paying requires a verified e-mail. An unverified account is one somebody
| typed an address into; letting it buy things is how a marketplace becomes a
| card-testing service.
|
| The webhook route is deliberately outside every auth middleware — the caller
| is a bank's server, not a session — and outside the API rate limiter, because
| throttling a provider makes it retry, which is the opposite of what a
| throttle is for. What stands in for authentication is the signature checked
| over the exact bytes received.
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class, EnsureEmailIsVerified::class])
    ->prefix('checkout')
    ->as('checkout.')
    ->group(function (): void {

        Route::get('/', [CheckoutController::class, 'show'])->name('show');
        Route::get('methods', [CheckoutController::class, 'methods'])->name('methods');

        Route::post('/', [CheckoutController::class, 'start'])->name('start');
        Route::post('credits', [CheckoutController::class, 'startCredits'])->name('credits');
        Route::delete('/', [CheckoutController::class, 'cancel'])->name('cancel');

        /*
         * The one route where a duplicate costs real money, so it is the one route that
         * honours an Idempotency-Key: a retry from a flaky connection replays the first
         * answer instead of starting a second payment.
         */
        Route::post('pay', [CheckoutController::class, 'pay'])
            ->middleware(EnsureIdempotentRequest::class)
            ->name('pay');
    });

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])
    ->get('payments/{intent}', [CheckoutController::class, 'payment'])
    ->name('payments.show');

/*
 * The test provider's own endpoints — a stand-in 3DS page and the webhook it would have
 * sent. Both 404 unless the fake gateway is enabled, which it is not anywhere real money
 * moves.
 */
Route::prefix('payments/fake/{externalId}')->as('payments.fake.')->group(function (): void {
    Route::get('challenge', [FakeGatewayController::class, 'challenge'])->name('challenge');
    Route::post('complete', [FakeGatewayController::class, 'complete'])->name('complete');
});

Route::post('payments/webhooks/{gateway}', PaymentWebhookController::class)
    ->withoutMiddleware(['throttle:api'])
    ->name('payments.webhook');

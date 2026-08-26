<?php

declare(strict_types=1);

use App\Domains\Ai\Exceptions\AiJobRefused;
use App\Domains\Commerce\Exceptions\CartRefused;
use App\Domains\Credits\Console\SweepExpiredCreditsCommand;
use App\Domains\Credits\Exceptions\InsufficientCredits;
use App\Domains\Identity\Console\GrantRoleCommand;
use App\Domains\Inventory\Console\ReleaseExpiredReservationsCommand;
use App\Domains\Matching\Console\EmbedCatalogueCommand;
use App\Domains\Orders\Exceptions\OrderRefused;
use App\Domains\Payments\Console\ExpireBankTransfersCommand;
use App\Domains\Payments\Console\ExpireCheckoutSessionsCommand;
use App\Domains\Payments\Exceptions\CheckoutRefused;
use App\Domains\Payments\Exceptions\GatewayUnavailable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Domain commands live beside their domain, so they are registered explicitly
    // rather than discovered from app/Console/Commands.
    ->withCommands([
        GrantRoleCommand::class,
        ReleaseExpiredReservationsCommand::class,
        SweepExpiredCreditsCommand::class,
        EmbedCatalogueCommand::class,
        ExpireCheckoutSessionsCommand::class,
        ExpireBankTransfersCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The three Nuxt clients are separate origins; CORS is configured in config/cors.php.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Everything under /api answers JSON, including validation and auth failures.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Running out of credits is a 422, not a 500.
         *
         * Rendered here rather than caught in each controller because it is thrown from
         * deep inside the ledger and surfaces through several routes — a design, a
         * refinement, anything a later phase spends credits on. One place to state the
         * status means a new caller cannot forget to, and the message is already written
         * for the customer with the two numbers they need.
         */
        $exceptions->render(function (InsufficientCredits $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'required' => $e->required,
                'available' => $e->available,
            ], 422);
        });

        // A refusal from the AI gateway carries the status it deserves: a paused feature
        // is a 503 that says "try later", a concurrency limit a 429 that says "you, later".
        $exceptions->render(function (AiJobRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        /*
         * A basket refusal carries its own status too. A stock problem is a 409 — the
         * request was fine and the world changed underneath it — while a quantity of two
         * hundred is a 422, because the request itself was wrong. A controller inventing
         * its own answer would get that distinction wrong sooner or later.
         */
        $exceptions->render(function (CartRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        // The same shape once more for checkout: an expired session is a 409 because the
        // world moved on, a missing address a 422 because the request was incomplete.
        $exceptions->render(function (CheckoutRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        // And for orders: an illegal transition is a 409 naming both states, so a seller
        // on a stale screen learns what the order actually is now.
        $exceptions->render(function (OrderRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        });

        /*
         * No provider can take the payment.
         *
         * A 503 rather than a 500: the customer did nothing wrong and trying again in a
         * moment might genuinely work. The gateway's name stays out of the response —
         * an error page is not the place to publish which integrations are switched off.
         */
        $exceptions->render(function (GatewayUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        });
    })->create();

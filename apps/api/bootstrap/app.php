<?php

declare(strict_types=1);

use App\Domains\Identity\Console\GrantRoleCommand;
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
    })->create();

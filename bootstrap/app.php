<?php

use App\Exceptions\ApiErrorRenderer;
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
    ->withMiddleware(function (Middleware $middleware): void {
        // role:driver / role:admin on routes (Phase 7). See App\Http\Middleware\EnsureRole.
        // `subscribed`: an active subscription is required (Phase 8). See App\Http\Middleware\EnsureSubscribed.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'subscribed' => \App\Http\Middleware\EnsureSubscribed::class,
        ]);

        // Every API request answers in the app's language (Accept-Language: en | fil). Prepended,
        // so validation and error messages later in the request already use it.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocaleFromRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every error on an API route uses the one standard JSON format (see ApiErrorRenderer).
        // Errors are still REPORTED (logged) as usual; this only controls the response.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiErrorRenderer::render($e);
            }

            return null; // non-API routes keep Laravel's default handling
        });
    })->create();

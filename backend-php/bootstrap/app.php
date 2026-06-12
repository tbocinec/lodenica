<?php

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(__DIR__.'/../routes/api.php');

            Route::middleware('api')
                ->group(__DIR__.'/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['api/*']);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Treat every /api/* request as a JSON client even when the caller
        // forgot to send `Accept: application/json` — stops Laravel from
        // trying to `route('login')`-redirect on AuthenticationException
        // and falling over because we have no `login` named route.
        $exceptions->shouldRenderJsonWhen(function ($request, $exception) {
            return $request->is('api/*') || $request->is('health*') || $request->expectsJson();
        });
        // Explicit handler for AuthenticationException — Laravel's
        // default flow calls `route('login')` even when the client wants
        // JSON, which 500s because we have no `login` named route in
        // an API-only app. Render our standard envelope instead.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->is('health*') || $request->expectsJson()) {
                return ApiExceptionRenderer::render($e, $request);
            }

            return null;
        });
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->is('health*') || $request->expectsJson()) {
                return ApiExceptionRenderer::render($e, $request);
            }

            return null;
        });
    })
    ->create();

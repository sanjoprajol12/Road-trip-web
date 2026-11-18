<?php

use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionMaxAge;
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
        // Sanctum SPA authentication: session cookie + CSRF for requests from the frontend origins.
        $middleware->statefulApi();
        $middleware->api(append: [SessionMaxAge::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['not-suspended' => EnsureNotSuspended::class]);
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api response is JSON, including errors (no HTML error pages, no stack traces outside debug).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();

<?php

use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SelectSessionAudience;
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
        // Riders and staff get separate sessions (cookie, table, guard, limits); decided before any session starts.
        $middleware->prepend(SelectSessionAudience::class);
        // Sanctum SPA authentication: session cookie + CSRF for requests from the frontend origins.
        $middleware->statefulApi();
        // Behind a hosting proxy (Vercel, the frontends' /api rewrites) set TRUSTED_PROXIES (comma-separated, or *)
        // so scheme, host and client IP (rate limits, audit) come from X-Forwarded-*. Unset locally.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->api(append: [SessionMaxAge::class, MaintenanceMode::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['not-suspended' => EnsureNotSuspended::class]);
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api response is JSON, including errors (no HTML error pages, no stack traces outside debug).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();

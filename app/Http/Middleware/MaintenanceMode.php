<?php

namespace App\Http\Middleware;

use App\Support\PreviewToken;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site-wide maintenance switch (a database setting, not `artisan down`, which is per server instance and
 * lost on Vercel). While on, the public API answers 503 with the message; the staff API, rider sign-in, the session
 * check, /site and /health keep working. Staff look at the closed site with a signed preview token
 * (POST /admin/preview-token, header X-Preview-Token), not with a rider session.
 */
class MaintenanceMode
{
    private const OPEN = ['api/v1/health', 'api/v1/site', 'api/v1/auth/*', 'api/v1/admin/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::get('maintenance.enabled') || $request->is(...self::OPEN) || PreviewToken::verify($request->header(PreviewToken::HEADER))) {
            return $next($request);
        }

        return response()->json(['message' => Settings::get('maintenance.message'), 'maintenance' => true], 503, ['Retry-After' => '600']);
    }
}

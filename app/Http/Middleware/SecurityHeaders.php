<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API responses (JSON, files) forbid nearly everything. This app serves no frontend (Option B), so the
 * only HTML it returns is framework error pages: same-origin only, inline styles for those pages, no scripts
 * from anywhere else. The frontends' own policies are set by the host that serves their static builds.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $isHtml = str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
        $response->headers->set('Content-Security-Policy', $isHtml
            ? "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
            : "default-src 'none'; frame-ancestors 'none'");
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

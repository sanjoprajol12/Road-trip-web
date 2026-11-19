<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session a fixed time after sign-in, however active it is (the idle limit is SESSION_LIFETIME). Uses the
 * current audience's guard and limit (rider or staff, chosen by SelectSessionAudience).
 */
class SessionMaxAge
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard(SelectSessionAudience::isStaffRequest($request) ? 'staff' : 'web');
        if ($request->hasSession() && $guard->check()) {
            $started = $request->session()->get('auth_started_at');
            if ($started === null) {
                $request->session()->put('auth_started_at', now()->timestamp);
            } elseif (now()->timestamp - $started > config('mrn.session_max_minutes') * 60) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
            }
        }

        return $next($request);
    }
}

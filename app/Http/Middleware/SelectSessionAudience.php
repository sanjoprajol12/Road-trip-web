<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Riders and staff never share a session. Requests to the staff API (/api/v1/admin) use the staff session cookie,
 * the `staff_sessions` table, the staff guard and the staff time limits; every other request uses the rider ones.
 * Runs first (global middleware), before any session is started. Everything is set explicitly in both directions,
 * so a long-lived process (or a test making several requests) never carries one audience's settings into the next.
 */
class SelectSessionAudience
{
    /** @var array<string, mixed>|null rider (default) settings, captured once from the configuration */
    private static ?array $rider = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$rider ??= [
            'session.cookie' => config('session.cookie'),
            'session.table' => config('session.table'),
            'session.lifetime' => config('session.lifetime'),
            'mrn.session_max_minutes' => config('mrn.session_max_minutes'),
        ];
        $staff = self::isStaffRequest($request);
        config($staff ? [
            'session.cookie' => self::$rider['session.cookie'].'_staff',
            'session.table' => 'staff_sessions',
            'session.lifetime' => config('mrn.staff_session.idle_minutes'),
            'mrn.session_max_minutes' => config('mrn.staff_session.max_minutes'),
        ] : self::$rider);
        if (config('session.driver') === 'database') {
            app('session')->forgetDrivers(); // the next store uses this audience's table and cookie
        } else {
            app('session')->driver()->setName(config('session.cookie')); // in-memory stores (tests): cookie name only
        }
        Auth::shouldUse($staff ? 'staff' : 'web');
        app()->forgetInstance('auth.driver'); // the session handler records the current guard's user id

        return $next($request);
    }

    public static function isStaffRequest(Request $request): bool
    {
        return $request->is('api/v1/admin', 'api/v1/admin/*');
    }
}

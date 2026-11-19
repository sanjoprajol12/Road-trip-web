<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Suspended riders can still read and sign out, but cannot contribute, apply, host or join. */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->isDeactivated()) {
            return response()->json(['message' => 'This account is deactivated.'], 403);
        }
        if ($user?->isSuspended()) {
            return response()->json(['message' => 'Your account is suspended until '.$user->suspended_until->format('j M Y').'.'], 403);
        }

        return $next($request);
    }
}

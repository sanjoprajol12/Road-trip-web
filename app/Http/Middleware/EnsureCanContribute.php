<?php

namespace App\Http\Middleware;

use App\Models\ContributionSuspension;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Contribution-only suspension: the rider keeps their account, trips, saved items and events, but cannot write reports. */
class EnsureCanContribute
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ($s = ContributionSuspension::activeFor($request->user()->id))) {
            return response()->json(['message' => 'Your contribution rights are paused until '.$s->until->format('j M Y').'. Reason: '.$s->reason, 'contribution_suspension' => $s->toRider()], 403);
        }

        return $next($request);
    }
}

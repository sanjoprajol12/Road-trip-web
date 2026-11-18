<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Destination;
use App\Models\Event;
use App\Models\RideRoute;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'verifications_pending' => Verification::where('status', 'pending')->count(),
            'contributions_pending' => Contribution::where('status', 'pending_review')->count(),
            'contributions_flagged' => Contribution::where('status', 'flagged')->count(),
            'destinations_published' => Destination::where('status', 'published')->count(),
            'destinations_draft' => Destination::where('status', 'draft')->count(),
            'routes_published' => RideRoute::where('status', 'published')->count(),
            'events_upcoming' => Event::whereIn('status', ['published', 'full', 'postponed'])->where('ends_at', '>=', now())->count(),
        ]]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\RouteResource;
use App\Models\Destination;
use App\Models\RideRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DestinationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Destination::published()->orderBy('name');
        if ($search = trim((string) $request->query('q'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('district', $like));
        }
        if ($province = $request->query('province')) {
            $q->where('province', $province);
        }

        return DestinationResource::collection($q->get());
    }

    public function show(string $slug): JsonResponse
    {
        $d = Destination::published()->where('slug', $slug)->firstOrFail();
        $routes = RideRoute::published()->with(['startDestination', 'endDestination'])
            ->where(fn ($w) => $w->where('start_destination_id', $d->id)->orWhere('end_destination_id', $d->id))->orderBy('name')->get();

        return response()->json(['data' => (new DestinationResource($d))->toArray(request()), 'routes' => RouteResource::collection($routes)]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RouteResource;
use App\Models\RideRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RouteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = RideRoute::published()->with(['startDestination', 'endDestination'])->orderBy('name');
        if ($dest = $request->query('destination')) {
            $q->where(fn ($w) => $w->whereHas('startDestination', fn ($d) => $d->where('slug', $dest))->orWhereHas('endDestination', fn ($d) => $d->where('slug', $dest)));
        }

        return RouteResource::collection($q->get());
    }

    public function show(string $slug): RouteResource
    {
        $route = RideRoute::published()->where('slug', $slug)
            ->with(['sources', 'startDestination', 'endDestination', 'segments.servicePoints' => fn ($q) => $q->where('status', 'published')])->firstOrFail();

        return new RouteResource($route);
    }
}

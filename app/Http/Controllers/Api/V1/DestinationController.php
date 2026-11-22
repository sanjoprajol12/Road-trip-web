<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DestinationResource;
use App\Http\Resources\RouteResource;
use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use App\Support\Geo;
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
        $d = Destination::published()->with('sources')->where('slug', $slug)->firstOrFail();
        $routes = RideRoute::published()->with(['startDestination', 'endDestination', 'segments'])
            ->where(fn ($w) => $w->where('start_destination_id', $d->id)->orWhere('end_destination_id', $d->id))->orderBy('name')->get();

        // Practical stops staff attached to this destination; drafts and archived points stay hidden.
        $services = ServicePoint::where('destination_id', $d->id)->where('status', 'published')->orderBy('kind')->orderBy('name')->get()
            ->map(fn (ServicePoint $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'name' => $p->name, 'notes' => $p->notes,
                'position' => $p->latitude !== null ? [$p->latitude, $p->longitude] : null,
                'last_confirmed_on' => $p->last_confirmed_on?->toDateString(),
            ]);

        return response()->json(['data' => (new DestinationResource($d))->toArray(request()), 'routes' => RouteResource::collection($routes), 'services' => $services,
            'nearby' => $d->latitude !== null && $d->longitude !== null ? $this->nearby($d, $routes->pluck('id')->all()) : null]);
    }

    /** Radii for "nearby", in km, straight line (owner decision D3/PostGIS would replace the PHP distance, not the meaning). */
    private const NEAR_KM = ['destinations' => 50, 'services' => 20, 'routes' => 15, 'places' => 20];

    /**
     * Published places near a destination: other destinations, services not attached to it, and routes whose recorded
     * stretches pass close by. Distances are straight-line, never riding distance.
     */
    private function nearby(Destination $d, array $routeIdsHere): array
    {
        [$lat, $lng] = [(float) $d->latitude, (float) $d->longitude];
        $box = function ($q, float $km, string $latCol = 'latitude', string $lngCol = 'longitude') use ($lat, $lng) {
            $dLat = $km / 111.0;
            $dLng = $km / (111.0 * max(0.2, cos(deg2rad($lat))));
            $q->whereBetween($latCol, [$lat - $dLat, $lat + $dLat])->whereBetween($lngCol, [$lng - $dLng, $lng + $dLng]);
        };
        $km = fn (float $a, float $b) => round(Geo::haversineKm($lat, $lng, $a, $b), 1);

        $destinations = Destination::published()->whereKeyNot($d->id)->whereNotNull('latitude')->tap(fn ($q) => $box($q, self::NEAR_KM['destinations']))->limit(200)->get()
            ->map(fn (Destination $o) => ['slug' => $o->slug, 'name' => $o->name, 'position' => [$o->latitude, $o->longitude], 'distance_km' => $km($o->latitude, $o->longitude)])
            ->filter(fn ($o) => $o['distance_km'] <= self::NEAR_KM['destinations'])->sortBy('distance_km')->take(10)->values();

        $services = ServicePoint::where('status', 'published')->where(fn ($w) => $w->whereNull('destination_id')->orWhere('destination_id', '!=', $d->id))
            ->whereNotNull('latitude')->tap(fn ($q) => $box($q, self::NEAR_KM['services']))->with(['destination', 'segment.route'])->limit(300)->get()
            ->map(fn (ServicePoint $p) => ['id' => $p->id, 'kind' => $p->kind, 'name' => $p->name, 'position' => [$p->latitude, $p->longitude],
                'distance_km' => $km($p->latitude, $p->longitude), 'place' => $p->destination?->name ?? ($p->segment ? "{$p->segment->start_name} to {$p->segment->end_name}" : null),
                'last_confirmed_on' => $p->last_confirmed_on?->toDateString()])
            ->filter(fn ($p) => $p['distance_km'] <= self::NEAR_KM['services'])->sortBy('distance_km')->take(20)->values();

        // shortcut: scans up to 200 published routes in PHP; becomes ST_DWithin on segment geography once PostGIS (D3) is enabled.
        // Routes passing nearby: nearest recorded stretch (start to end line) within the radius; routes that start or end here are listed already.
        $routes = RideRoute::published()->whereNotIn('id', $routeIdsHere)
            ->whereHas('segments', fn ($s) => $s->where(fn ($w) => $w->whereNotNull('path')->orWhere(fn ($e) => $e->whereNotNull('start_lat')->whereNotNull('end_lat'))))
            ->with('segments')->limit(200)->get()
            ->map(fn (RideRoute $r) => ['slug' => $r->slug, 'name' => $r->name,
                'distance_km' => round(collect($r->segments)->flatMap(fn ($s) => $s->lines())->min(fn ($l) => Geo::pointToSegmentKm($lat, $lng, ...$l)) ?? INF, 1),
                // Real recorded geometry only (imported tracks); routes without one are listed, not drawn.
                'paths' => $r->segments->filter(fn ($s) => $s->path)->map(fn ($s) => $s->path)->values()])
            ->filter(fn ($r) => $r['distance_km'] <= self::NEAR_KM['routes'])->sortBy('distance_km')->take(10)->values();

        $places = \App\Models\Place::published()->where(fn ($w) => $w->where('destination_id', $d->id)->orWhere(fn ($b) => $box($b, self::NEAR_KM['places'])))->limit(300)->get()
            ->map(fn (\App\Models\Place $p) => ['slug' => $p->slug, 'name' => $p->name, 'category' => $p->category, 'position' => [$p->latitude, $p->longitude], 'distance_km' => $km($p->latitude, $p->longitude)])
            ->sortBy('distance_km')->take(30)->values();

        return ['radius_km' => self::NEAR_KM, 'destinations' => $destinations, 'services' => $services, 'routes' => $routes, 'places' => $places];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RouteResource;
use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Explore Along My Route, curated mode: published routes between two destinations (either direction), and the
 * published services and destinations near each route's recorded line. Distances are straight-line from the line;
 * no riding detour, travel time or alternative is invented (routing provider waits for owner decision D2).
 */
class JourneyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'to' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', 'different:from'],
            'within_km' => ['nullable', 'numeric', 'between:1,50'],
        ]);
        $from = Destination::published()->where('slug', $data['from'])->first();
        $to = Destination::published()->where('slug', $data['to'])->first();
        if (! $from || ! $to) {
            return response()->json(['message' => 'Choose two published destinations.'], 404);
        }
        $within = (float) ($data['within_km'] ?? 10);

        $routes = RideRoute::published()
            ->with(['startDestination', 'endDestination', 'segments.servicePoints' => fn ($q) => $q->where('status', 'published')])
            ->where(fn ($w) => $w->where(['start_destination_id' => $from->id, 'end_destination_id' => $to->id])
                ->orWhere(fn ($r) => $r->where(['start_destination_id' => $to->id, 'end_destination_id' => $from->id])))
            ->orderBy('name')->limit(10)->get();

        return response()->json([
            'from' => ['slug' => $from->slug, 'name' => $from->name],
            'to' => ['slug' => $to->slug, 'name' => $to->name],
            'within_km' => $within,
            'data' => $routes->map(fn (RideRoute $r) => [
                'route' => (new RouteResource($r))->resolve($request),
                // The route is recorded in the other direction: the rider follows the stretches in reverse order.
                'reversed' => $r->start_destination_id === $to->id,
                'places' => $this->placesNear($r, $within, [$from->id, $to->id]),
            ])->values(),
        ]);
    }

    /** Services on the route's stretches (on route) and published places within $within km of its line (detour). */
    private function placesNear(RideRoute $r, float $within, array $endpoints): array
    {
        $lines = $r->segments->filter(fn ($s) => $s->lines() !== [])->values();
        $places = collect();
        foreach ($r->segments as $s) {
            foreach ($s->servicePoints as $p) {
                $places->push($this->servicePlace($p, true, 0.0, $s));
            }
        }
        if ($lines->isNotEmpty()) {
            // Bounding-box prefilter in SQL, exact straight-line distance in PHP.
            $pad = $within / 111.0;
            $lats = $lines->flatMap(fn ($s) => collect($s->lines())->flatMap(fn ($l) => [$l[0], $l[2]]));
            $lngs = $lines->flatMap(fn ($s) => collect($s->lines())->flatMap(fn ($l) => [$l[1], $l[3]]));
            $box = fn ($q) => $q->whereBetween('latitude', [$lats->min() - $pad, $lats->max() + $pad])
                ->whereBetween('longitude', [$lngs->min() - $pad * 1.2, $lngs->max() + $pad * 1.2]);
            $onRoute = $places->pluck('id')->all();

            ServicePoint::where('status', 'published')->whereNotNull('latitude')->whereNotIn('id', $onRoute)->tap($box)->with(['destination', 'segment'])->limit(500)->get()
                ->each(function (ServicePoint $p) use ($lines, $within, $places) {
                    [$km, $seg] = $this->nearest($lines, $p->latitude, $p->longitude);
                    if ($km <= $within) {
                        $places->push($this->servicePlace($p, false, $km, $seg));
                    }
                });
            Destination::published()->whereNotNull('latitude')->whereNotIn('id', $endpoints)->tap($box)->limit(200)->get()
                ->each(function (Destination $d) use ($lines, $within, $places) {
                    [$km, $seg] = $this->nearest($lines, (float) $d->latitude, (float) $d->longitude);
                    if ($km <= $within) {
                        $places->push(['type' => 'destination', 'id' => $d->id, 'kind' => 'destination', 'name' => $d->name, 'notes' => $d->summary,
                            'position' => [(float) $d->latitude, (float) $d->longitude], 'on_route' => false, 'distance_km' => round($km, 1),
                            'near' => "{$seg->start_name} to {$seg->end_name}", 'link' => "/explore/{$d->slug}", 'last_confirmed_on' => $d->last_confirmed_on?->toDateString()]);
                    }
                });
            \App\Models\Place::published()->tap($box)->limit(300)->get()
                ->each(function (\App\Models\Place $p) use ($lines, $within, $places) {
                    [$km, $seg] = $this->nearest($lines, $p->latitude, $p->longitude);
                    if ($km <= $within) {
                        $places->push(['type' => 'place', 'id' => $p->id, 'kind' => $p->category, 'name' => $p->name, 'notes' => $p->summary,
                            'position' => [$p->latitude, $p->longitude], 'on_route' => false, 'distance_km' => round($km, 1),
                            'near' => "{$seg->start_name} to {$seg->end_name}", 'link' => "/places/{$p->slug}", 'last_confirmed_on' => $p->last_confirmed_on?->toDateString()]);
                    }
                });
        }

        return $places->sortBy([['on_route', 'desc'], ['distance_km', 'asc']])->values()->all();
    }

    /** @return array{0: float, 1: \App\Models\RouteSegment} */
    private function nearest(Collection $lines, float $lat, float $lng): array
    {
        return $lines->map(fn ($s) => [min(array_map(fn ($l) => Geo::pointToSegmentKm($lat, $lng, ...$l), $s->lines())), $s])
            ->sortBy(0)->first();
    }

    private function servicePlace(ServicePoint $p, bool $onRoute, float $km, $segment): array
    {
        return ['type' => 'service', 'id' => $p->id, 'kind' => $p->kind, 'name' => $p->name, 'notes' => $p->notes,
            'position' => $p->latitude !== null ? [$p->latitude, $p->longitude] : null, 'on_route' => $onRoute, 'distance_km' => round($km, 1),
            'near' => $segment ? "{$segment->start_name} to {$segment->end_name}" : null,
            'link' => null, 'last_confirmed_on' => $p->last_confirmed_on?->toDateString()];
    }
}

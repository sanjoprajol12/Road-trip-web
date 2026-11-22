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
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'in:Koshi,Madhesh,Bagmati,Gandaki,Lumbini,Karnali,Sudurpashchim'],
            'max_km' => ['nullable', 'numeric', 'between:1,5000'],
            'surface' => ['nullable', 'in:paved_only,rough'],
            'has_track' => ['nullable', 'boolean'],
            'destination' => ['nullable', 'string', 'max:80'],
        ]);
        $q = RideRoute::published()->with(['startDestination', 'endDestination'])->withCount('segments')->orderBy('name');
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.addcslashes($s, '%_\\').'%';
            $q->where(fn ($w) => $w->whereLike('name', $like)->orWhereLike('summary', $like));
        }
        $q->when($f['province'] ?? null, fn ($q, $p) => $q->where(fn ($w) => $w->whereHas('startDestination', fn ($d) => $d->where('province', $p))->orWhereHas('endDestination', fn ($d) => $d->where('province', $p))));
        // Routes without a recorded total distance are left out of a distance filter (unknown is not "short").
        $q->when($f['max_km'] ?? null, fn ($q, $km) => $q->whereNotNull('distance_km')->where('distance_km', '<=', $km));
        // Surface filters use the recorded stretches: "paved only" = at least one stretch and every stretch paved.
        if (($f['surface'] ?? null) === 'paved_only') {
            $q->whereHas('segments')->whereDoesntHave('segments', fn ($s) => $s->where('surface', '!=', 'paved'));
        } elseif (($f['surface'] ?? null) === 'rough') {
            $q->whereHas('segments', fn ($s) => $s->whereIn('surface', ['gravel', 'dirt']));
        }
        $q->when(filter_var($f['has_track'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->whereHas('segments', fn ($s) => $s->whereNotNull('path')));
        if ($dest = $f['destination'] ?? null) {
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

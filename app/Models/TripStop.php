<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TripStop extends Model
{
    use HasUuids;

    protected $fillable = ['position', 'day', 'name', 'destination_id', 'route_id', 'overnight', 'note', 'service_point_id', 'place_id', 'route_segment_id', 'route_part'];

    /** Linked records are shown with every stop (a trip has few stops). */
    protected $with = ['place', 'destination', 'servicePoint', 'route.segments', 'segment'];

    protected function casts(): array
    {
        return ['overnight' => 'boolean'];
    }

    public function place(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function destination(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function route(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RideRoute::class, 'route_id');
    }

    public function segment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RouteSegment::class, 'route_segment_id');
    }

    public function servicePoint(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    /** What trip members see. Linked records only while they are published; the stop's own name always stays. */
    public function toTrip(): array
    {
        $pos = fn ($lat, $lng) => $lat !== null && $lng !== null ? [(float) $lat, (float) $lng] : null;
        $place = $this->place?->status === 'published' ? $this->place : null;
        $dest = $this->destination?->status?->value === 'published' ? $this->destination : null;
        $svc = $this->servicePoint?->status?->value === 'published' ? $this->servicePoint : null;

        $route = $this->route?->status?->value === 'published' ? $this->route : null;
        $seg = $route && $this->segment?->route_id === $route->id ? $this->segment : null;
        $stretch = fn (RouteSegment $g) => ['from' => $g->start_name, 'to' => $g->end_name, 'distance_km' => $g->distance_km, 'estimated_hours' => $g->estimated_hours, 'surface' => $g->surface, 'has_track' => (bool) $g->path];

        return $this->only(['id', 'position', 'day', 'name', 'destination_id', 'route_id', 'service_point_id', 'place_id', 'route_segment_id', 'route_part', 'overnight', 'note']) + [
            // Recorded values only; a count of stretches missing each value lets the trip say what is unknown.
            'route' => $route ? ['slug' => $route->slug, 'name' => $route->name, 'distance_km' => $route->distance_km, 'estimated_hours' => $route->estimated_hours,
                'stretches' => $route->segments->count(), 'without_distance' => $route->segments->whereNull('distance_km')->count(),
                'without_time' => $route->segments->whereNull('estimated_hours')->count(), 'without_track' => $route->segments->filter(fn ($g) => ! $g->path)->count()] : null,
            'stretch' => $seg && $this->route_part === 'stretch' ? $stretch($seg) : null,
            'checkpoint' => $seg && str_starts_with((string) $this->route_part, 'checkpoint_') ? ($this->route_part === 'checkpoint_end'
                ? ['name' => $seg->end_name, 'position' => $pos($seg->end_lat, $seg->end_lng)] : ['name' => $seg->start_name, 'position' => $pos($seg->start_lat, $seg->start_lng)]) : null,
            'place' => $place ? ['slug' => $place->slug, 'name' => $place->name, 'category' => $place->category, 'position' => $pos($place->latitude, $place->longitude)] : null,
            'destination' => $dest ? ['slug' => $dest->slug, 'name' => $dest->name, 'position' => $pos($dest->latitude, $dest->longitude)] : null,
            'service' => $svc ? ['name' => $svc->name, 'kind' => $svc->kind, 'position' => $pos($svc->latitude, $svc->longitude)] : null,
            // Recorded tracks only, for the trip map; a stretch without one gets no line.
            // shortcut: sends full tracks (up to 5000 points each); simplify server-side if trips with long routes get slow.
            'tracks' => $route ? ($this->route_part === 'route' ? $route->segments->pluck('path')->filter()->values()->all() : ($seg?->path && $this->route_part === 'stretch' ? [$seg->path] : [])) : [],
            'linked_unavailable' => ($this->place_id && ! $place) || ($this->service_point_id && ! $svc) || ($this->destination_id && ! $dest)
                || ($this->route_part && ! $route) || (in_array($this->route_part, ['stretch', 'checkpoint_start', 'checkpoint_end'], true) && ! $seg),
        ];
    }
}

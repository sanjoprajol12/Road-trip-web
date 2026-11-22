<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\RideRoute;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Route parts in a trip (whole route, stretches, checkpoints) and stop order. See docs/architecture/TRIP_ROUTE_PARTS.md. */
class TripItineraryController extends Controller
{
    public function addRoute(Request $request, Trip $trip): TripResource
    {
        $this->authorize('edit', $trip);
        $data = $request->validate([
            'route_id' => ['required', 'uuid', Rule::exists('routes', 'id')->where('status', 'published')],
            'whole' => ['sometimes', 'boolean'],
            'reversed' => ['sometimes', 'boolean'],
            'day' => ['nullable', 'integer', 'between:1,365'],
            'segment_ids' => ['sometimes', 'array', 'max:60'],
            'segment_ids.*' => ['uuid'],
            'checkpoints' => ['sometimes', 'array', 'max:61'],
            'checkpoints.*.segment_id' => ['required', 'uuid'],
            'checkpoints.*.at' => ['required', Rule::in(['start', 'end'])],
        ], ['route_id.exists' => 'This route is not published, so it cannot be added.'], ['segment_ids' => 'stretches']);
        $whole = (bool) ($data['whole'] ?? false);
        $picked = ! empty($data['segment_ids']) || ! empty($data['checkpoints']);
        if ($whole === $picked) {
            throw ValidationException::withMessages(['segment_ids' => [$whole ? 'Add the whole route or choose stretches, not both.' : 'Choose the whole route, a stretch or a checkpoint.']]);
        }
        $route = RideRoute::with('segments')->findOrFail($data['route_id']);
        $segs = $route->segments->values();
        $index = $segs->pluck('id')->flip();

        // [order key in recorded riding order, part, segment, name]; checkpoint k sits before stretch k.
        $items = [];
        if ($whole) {
            $items[] = [0, 'route', null, $route->name];
        }
        foreach (array_unique($data['segment_ids'] ?? []) as $id) {
            $i = $index[$id] ?? throw ValidationException::withMessages(['segment_ids' => ['A chosen stretch is not part of this route. Reload the route.']]);
            $g = $segs[$i];
            $items["stretch:{$id}"] = [2 * $i + 1, 'stretch', $g, ! empty($data['reversed']) ? "{$g->end_name} to {$g->start_name}" : "{$g->start_name} to {$g->end_name}"];
        }
        foreach ($data['checkpoints'] ?? [] as $c) {
            $i = $index[$c['segment_id']] ?? throw ValidationException::withMessages(['checkpoints' => ['A chosen checkpoint is not part of this route. Reload the route.']]);
            // One canonical row per checkpoint: the end of a stretch is the start of the next one.
            $k = $c['at'] === 'end' ? $i + 1 : $i;
            $g = $segs[min($k, $segs->count() - 1)];
            $part = $k === $segs->count() ? 'checkpoint_end' : 'checkpoint_start';
            $items["{$part}:{$g->id}"] = [2 * $k, $part, $g, $part === 'checkpoint_end' ? $g->end_name : $g->start_name];
        }
        usort($items, fn ($a, $b) => ! empty($data['reversed']) ? $b[0] <=> $a[0] : $a[0] <=> $b[0]);

        DB::transaction(function () use ($trip, $route, $items, $whole, $data) {
            Trip::whereKey($trip->id)->lockForUpdate()->first(); // one selection at a time per trip: positions and checks stay consistent
            $existing = $trip->stops()->where('route_id', $route->id)->whereNotNull('route_part')->get(['route_part', 'route_segment_id', 'name']);
            $clash = collect($items)->filter(fn ($it) => $existing->contains(fn ($s) => $s->route_part === $it[1] && ($it[1] === 'route' || $s->route_segment_id === $it[2]->id)))->map(fn ($it) => $it[3]);
            if ($clash->isNotEmpty()) {
                throw ValidationException::withMessages(['segment_ids' => ['Already on this trip: '.$clash->join(', ').'.']]);
            }
            // Whole route and some of its stretches would count the same road twice.
            if ($whole && $existing->contains('route_part', 'stretch')) {
                throw ValidationException::withMessages(['segment_ids' => ['Some stretches of this route are already on this trip. Remove them first, or add the remaining stretches.']]);
            }
            if (! $whole && $existing->contains('route_part', 'route') && collect($items)->contains(fn ($it) => $it[1] === 'stretch')) {
                throw ValidationException::withMessages(['segment_ids' => ['The whole route is already on this trip, so its stretches are included. You can still add checkpoints.']]);
            }
            $position = (int) $trip->stops()->max('position');
            foreach ($items as [, $part, $g, $name]) {
                $trip->stops()->create(['position' => ++$position, 'day' => $data['day'] ?? null, 'name' => Str::limit($name, 117), 'route_id' => $route->id,
                    'route_segment_id' => $g?->id, 'route_part' => $part]);
            }
            $trip->touch();
        });

        return new TripResource($trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']));
    }

    /** New order for every stop of the trip; a stale list (stop added or removed meanwhile) is refused. */
    public function reorder(Request $request, Trip $trip): TripResource
    {
        $this->authorize('edit', $trip);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['uuid', 'distinct']])['ids'];
        DB::transaction(function () use ($trip, $ids) {
            Trip::whereKey($trip->id)->lockForUpdate()->first();
            $current = $trip->stops()->pluck('id')->all();
            if (count($ids) !== count($current) || array_diff($ids, $current)) {
                throw ValidationException::withMessages(['ids' => ['The itinerary changed since you opened it. Reload the trip and try again.']]);
            }
            foreach ($ids as $i => $id) {
                $trip->stops()->whereKey($id)->update(['position' => $i + 1]);
            }
            $trip->touch();
        });

        return new TripResource($trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']));
    }
}

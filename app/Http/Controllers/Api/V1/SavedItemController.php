<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A rider's saved destinations, routes, services and experiences. Rows belong to the signed-in rider only;
 * only published items can be saved, and items that are no longer public drop out of the list (the row stays).
 */
class SavedItemController extends Controller
{
    private const TYPES = ['destination', 'route', 'service', 'contribution', 'place'];

    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['nullable', Rule::in(self::TYPES)], 'kind' => ['nullable', 'string', 'max:20']]);
        $rows = DB::table('saved_items')->where('user_id', $request->user()->id)
            ->when($type['type'] ?? null, fn ($q, $t) => $q->where('item_type', $t))->orderByDesc('created_at')->get();
        $ids = fn (string $t) => $rows->where('item_type', $t)->pluck('item_id');

        $items = collect()
            ->merge(Destination::published()->whereIn('id', $ids('destination'))->get()->map(fn (Destination $d) => [
                'type' => 'destination', 'id' => $d->id, 'name' => $d->name, 'kind' => null, 'link' => "/explore/{$d->slug}",
                'place' => collect([$d->district, $d->province])->filter()->implode(', ') ?: null,
                'latitude' => $d->latitude, 'longitude' => $d->longitude, 'last_confirmed_on' => $d->last_confirmed_on?->toDateString(),
            ]))
            ->merge(RideRoute::published()->with(['startDestination', 'endDestination'])->whereIn('id', $ids('route'))->get()->map(fn (RideRoute $r) => [
                'type' => 'route', 'id' => $r->id, 'name' => $r->name, 'kind' => null, 'link' => "/routes/{$r->slug}",
                'place' => $r->startDestination && $r->endDestination ? "{$r->startDestination->name} to {$r->endDestination->name}" : null,
                'latitude' => null, 'longitude' => null, 'last_confirmed_on' => $r->last_confirmed_on?->toDateString(),
            ]))
            ->merge(ServicePoint::where('status', 'published')->with(['destination', 'segment.route'])->whereIn('id', $ids('service'))
                ->when($type['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))->get()->map(fn (ServicePoint $p) => $this->service($p)))
            ->merge(\App\Models\Place::published()->with('destination')->whereIn('id', $ids('place'))->get()->map(fn (\App\Models\Place $p) => [
                'type' => 'place', 'id' => $p->id, 'name' => $p->name, 'kind' => $p->category, 'link' => "/places/{$p->slug}", 'place' => $p->destination?->name,
                'latitude' => $p->latitude, 'longitude' => $p->longitude, 'last_confirmed_on' => $p->last_confirmed_on?->toDateString(),
            ]))
            ->merge(Contribution::visible()->whereIn('id', $ids('contribution'))->get()->map(fn (Contribution $c) => [
                'type' => 'contribution', 'id' => $c->id, 'name' => $c->title, 'kind' => $c->kind->value, 'link' => "/experiences/{$c->id}",
                'place' => null, 'latitude' => null, 'longitude' => null, 'last_confirmed_on' => $c->last_confirmed_on?->toDateString(),
            ]));

        $saved = $rows->mapWithKeys(fn ($r) => ["{$r->item_type}:{$r->item_id}" => $r->created_at]);

        return response()->json(['data' => $items->map(fn ($i) => $i + ['saved_at' => $saved["{$i['type']}:{$i['id']}"] ?? null])
            ->sortByDesc('saved_at')->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(self::TYPES)], 'id' => ['required', 'uuid']]);
        abort_unless($this->isPublic($data['type'], $data['id']), 404);
        DB::table('saved_items')->insertOrIgnore(['user_id' => $request->user()->id, 'item_type' => $data['type'], 'item_id' => $data['id'], 'created_at' => now()]);

        return response()->json(['saved' => true], 201);
    }

    public function destroy(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        DB::table('saved_items')->where(['user_id' => $request->user()->id, 'item_type' => $type, 'item_id' => $id])->delete();

        return response()->json(null, 204);
    }

    private function isPublic(string $type, string $id): bool
    {
        return match ($type) {
            'destination' => Destination::published()->whereKey($id)->exists(),
            'route' => RideRoute::published()->whereKey($id)->exists(),
            'service' => ServicePoint::where('status', 'published')->whereKey($id)->exists(),
            'contribution' => Contribution::visible()->whereKey($id)->exists(),
            'place' => \App\Models\Place::published()->whereKey($id)->exists(),
        };
    }

    private function service(ServicePoint $p): array
    {
        $route = $p->segment?->route;
        $link = $p->destination ? "/explore/{$p->destination->slug}" : ($route ? "/routes/{$route->slug}" : null);
        $place = $p->destination?->name ?? ($p->segment ? "{$p->segment->start_name} to {$p->segment->end_name}".($route ? ", {$route->name}" : '') : null);

        return ['type' => 'service', 'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'link' => $link, 'place' => $place,
            'latitude' => $p->latitude, 'longitude' => $p->longitude, 'last_confirmed_on' => $p->last_confirmed_on?->toDateString()];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RouteResource;
use App\Models\RideRoute;
use App\Models\Source;
use App\Support\Audit;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RouteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RouteResource::collection(RideRoute::with(['startDestination', 'endDestination'])->orderBy('name')->get());
    }

    public function show(string $route): RouteResource
    {
        return new RouteResource(RideRoute::with(['startDestination', 'endDestination', 'segments.servicePoints', 'sources'])->findOrFail($route));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $r = DB::transaction(function () use ($request, $data) {
            $r = new RideRoute(Arr::except($data, 'sources'));
            $r->created_by_staff_id = $request->user()->id;
            $r->save();
            Audit::record('route.created', $r, null, $r->only(['name', 'status']));
            $r->syncSources($data['sources'] ?? []);

            return $r;
        });

        return $this->show($r->id)->response()->setStatusCode(201);
    }

    public function update(Request $request, string $route): RouteResource
    {
        $r = RideRoute::findOrFail($route);
        $data = $this->validated($request, $r);
        DB::transaction(function () use ($r, $data) {
            $old = $r->only(['name', 'status', 'distance_km', 'last_confirmed_on']);
            $r->update(Arr::except($data, 'sources'));
            Audit::record('route.updated', $r, $old, $r->only(['name', 'status', 'distance_km', 'last_confirmed_on']));
            if (array_key_exists('sources', $data)) {
                $r->syncSources($data['sources']);
            }
        });

        return $this->show($r->id);
    }

    public function destroy(string $route): JsonResponse
    {
        $r = RideRoute::findOrFail($route);
        $r->delete();
        Audit::record('route.deleted', $r, $r->only(['name']));

        return response()->json(null, 204);
    }

    /**
     * Replaces the ordered segment list. Existing segments (sent with their id) keep their id, so
     * road reports filed against them stay attached; segments left out are removed.
     */
    public function replaceSegments(Request $request, string $route): RouteResource
    {
        $r = RideRoute::findOrFail($route);
        $data = $request->validate([
            'segments' => ['present', 'array', 'max:60'],
            'segments.*.id' => ['nullable', 'uuid'],
            'segments.*.start_name' => ['required', 'string', 'max:80'],
            'segments.*.end_name' => ['required', 'string', 'max:80'],
            'segments.*.start_lat' => ['nullable', 'numeric', 'between:26,31'],
            'segments.*.start_lng' => ['nullable', 'numeric', 'between:80,89'],
            'segments.*.end_lat' => ['nullable', 'numeric', 'between:26,31'],
            'segments.*.end_lng' => ['nullable', 'numeric', 'between:80,89'],
            'segments.*.distance_km' => ['nullable', 'numeric', 'between:0.1,1000'],
            'segments.*.surface' => ['required', Rule::in(['paved', 'mixed', 'gravel', 'dirt', 'unknown'])],
            'segments.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($r, $data) {
            $keep = collect($data['segments'])->pluck('id')->filter();
            $removed = $r->segments()->whereNotIn('id', $keep)->get();
            $withReports = $removed->filter(fn ($s) => \App\Models\Contribution::where('route_segment_id', $s->id)->exists());
            if ($withReports->isNotEmpty()) {
                $names = $withReports->map(fn ($s) => "{$s->start_name} to {$s->end_name}")->join(', ');
                throw \Illuminate\Validation\ValidationException::withMessages(['segments' => ["Riders have reported on {$names}. Keep that segment (you can rename or move it) so their reports stay attached."]]);
            }
            $r->segments()->whereKey($removed->pluck('id'))->delete();
            // Move positions out of the way first so the (route, position) unique index never collides mid-update.
            $r->segments()->update(['position' => DB::raw('position + 1000')]);
            foreach ($data['segments'] as $i => $s) {
                $values = collect($s)->except('id')->all() + ['position' => $i + 1];
                ! empty($s['id']) ? $r->segments()->whereKey($s['id'])->firstOrFail()->update($values) : $r->segments()->create($values);
            }
            Audit::record('route.segments_replaced', $r, null, ['count' => count($data['segments'])]);
        });

        return $this->show($r->id);
    }

    private function validated(Request $request, ?RideRoute $r = null): array
    {
        $req = $r ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => [$req, 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('routes')->ignore($r?->id)],
            'name' => [$req, 'string', 'min:3', 'max:120'],
            'summary' => ['nullable', 'string', 'max:400'],
            'start_destination_id' => ['nullable', 'uuid', 'exists:destinations,id'],
            'end_destination_id' => ['nullable', 'uuid', 'exists:destinations,id'],
            'distance_km' => ['nullable', 'numeric', 'between:0.1,5000'],
            'estimated_hours' => ['nullable', 'numeric', 'between:0.1,500'],
            'status' => [$req, Rule::in(['draft', 'published', 'archived'])],
            'last_confirmed_on' => ['nullable', 'date', 'before_or_equal:today'],
            ...Source::rules(),
        ]);
    }
}

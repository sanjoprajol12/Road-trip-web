<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServicePoint;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServicePointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ServicePoint::orderBy('name');
        $q->when($request->query('segment'), fn ($q, $s) => $q->where('route_segment_id', $s));
        $q->when($request->query('destination'), fn ($q, $d) => $q->where('destination_id', $d));

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = ServicePoint::create($this->validated($request));
        Audit::record('service_point.created', $p, null, $p->only(['name', 'kind', 'status']));

        return response()->json(['data' => $p->refresh()], 201);
    }

    public function update(Request $request, string $point): JsonResponse
    {
        $p = ServicePoint::findOrFail($point);
        $p->update($this->validated($request, true));
        Audit::record('service_point.updated', $p, null, $p->only(['name', 'kind', 'status', 'last_confirmed_on']));

        return response()->json(['data' => $p]);
    }

    public function destroy(string $point): JsonResponse
    {
        $p = ServicePoint::findOrFail($point);
        $p->delete();
        Audit::record('service_point.deleted', $p, $p->only(['name', 'kind']));

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'kind' => [$req, Rule::in(['fuel', 'mechanic', 'medical', 'lodging', 'food', 'atm', 'connectivity'])],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'destination_id' => ['nullable', 'required_without:route_segment_id', 'uuid', 'exists:destinations,id'],
            'route_segment_id' => ['nullable', 'required_without:destination_id', 'uuid', 'exists:route_segments,id'],
            'latitude' => ['nullable', 'numeric', 'between:26,31'],
            'longitude' => ['nullable', 'numeric', 'between:80,89'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => [$req, Rule::in(['draft', 'published', 'archived'])],
            'last_confirmed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }
}

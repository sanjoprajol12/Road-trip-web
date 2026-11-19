<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DestinationResource;
use App\Models\Destination;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DestinationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DestinationResource::collection(Destination::orderBy('name')->get());
    }

    public function store(Request $request): DestinationResource
    {
        $d = new Destination($this->validated($request));
        $d->created_by_staff_id = $request->user()->id;
        $d->save();
        Audit::record('destination.created', $d, null, $d->only(['name', 'status']));

        return new DestinationResource($d->refresh());
    }

    public function update(Request $request, Destination $destination): DestinationResource
    {
        $old = $destination->only(['name', 'status', 'summary', 'overview', 'last_confirmed_on']);
        $destination->update($this->validated($request, $destination));
        Audit::record('destination.updated', $destination, $old, $destination->only(['name', 'status', 'summary', 'overview', 'last_confirmed_on']));

        return new DestinationResource($destination);
    }

    /** Soft delete only: contributions and trips keep their reference. */
    public function destroy(Destination $destination): JsonResponse
    {
        $destination->delete();
        Audit::record('destination.deleted', $destination, $destination->only(['name', 'status']));

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Destination $d = null): array
    {
        $req = $d ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => [$req, 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('destinations')->ignore($d?->id)],
            'name' => [$req, 'string', 'min:2', 'max:80'],
            'province' => ['nullable', Rule::in(['Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim'])],
            'district' => ['nullable', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:400'],
            'overview' => ['nullable', 'string', 'max:20000'],
            'elevation_m' => ['nullable', 'integer', 'between:0,9000'],
            'latitude' => ['nullable', 'numeric', 'between:26,31'],
            'longitude' => ['nullable', 'numeric', 'between:80,89'],
            'photo_path' => ['nullable', 'string', 'max:255', 'regex:#^/images/[a-z0-9/_-]+$#'],
            'photo_alt' => ['nullable', 'required_with:photo_path', 'string', 'max:255'],
            'photo_credit' => ['nullable', 'required_with:photo_path', 'string', 'max:255'],
            'status' => [$req, Rule::in(['draft', 'published', 'archived'])],
            'last_confirmed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }
}

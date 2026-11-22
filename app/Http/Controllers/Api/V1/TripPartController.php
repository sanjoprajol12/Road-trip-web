<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stops, expenses and checklist items of a trip. Editors and owners change them; viewers read. */
class TripPartController extends Controller
{
    private function relation(Trip $trip, string $part): HasMany
    {
        return match ($part) { 'stops' => $trip->stops(), 'expenses' => $trip->expenses(), 'checklist' => $trip->checklist() };
    }

    private function rules(string $part, bool $partial): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return match ($part) {
            'stops' => [
                'name' => [$r, 'string', 'min:2', 'max:120'],
                'position' => ['sometimes', 'integer', 'between:0,500'],
                'day' => ['nullable', 'integer', 'between:1,365'],
                'destination_id' => ['nullable', 'uuid', Rule::exists('destinations', 'id')->where('status', 'published')],
                'route_id' => ['nullable', 'uuid', Rule::exists('routes', 'id')->where('status', 'published')],
                'service_point_id' => ['nullable', 'uuid', Rule::exists('service_points', 'id')->where('status', 'published')],
                'place_id' => ['nullable', 'uuid', Rule::exists('places', 'id')->where('status', 'published')],
                'overnight' => ['sometimes', 'boolean'],
                'note' => ['nullable', 'string', 'max:1000'],
            ],
            'expenses' => [
                'label' => [$r, 'string', 'min:2', 'max:120'],
                'category' => ['sometimes', Rule::in(['fuel', 'lodging', 'food', 'permits', 'repairs', 'other'])],
                // Planned costs may be unknown yet; spent ones need the amount (checked on the final row in clean()).
                'amount_npr' => ['nullable', 'numeric', 'between:0,10000000'],
                'status' => ['sometimes', Rule::in(['planned', 'spent'])],
                'paid_by' => ['nullable', 'uuid'],
            ],
            'checklist' => [
                'label' => [$r, 'string', 'min:2', 'max:200'],
                'section' => ['sometimes', Rule::in(['motorcycle', 'personal', 'trip'])],
                'done' => ['sometimes', 'boolean'],
                'position' => ['sometimes', 'integer', 'between:0,500'],
            ],
        };
    }

    public function store(Request $request, Trip $trip, string $part): TripResource
    {
        $this->authorize('edit', $trip);
        $data = $this->clean($trip, $request->validate($this->rules($part, false)), $part);
        // The same service twice in one trip is a mistake (a destination may repeat: out and back).
        if ($part === 'stops' && isset($data['service_point_id']) && $trip->stops()->where('service_point_id', $data['service_point_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['service_point_id' => ['This service is already a stop on this trip.']]);
        }
        // A place appears once per trip (the database refuses a second one too).
        if ($part === 'stops' && isset($data['place_id']) && $trip->stops()->where('place_id', $data['place_id'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['place_id' => ['This place is already a stop on this trip.']]);
        }
        // A stop linking only a route is a whole-route stop (session 19): the same rules as TripItineraryController apply.
        if ($part === 'stops' && isset($data['route_id']) && ! array_filter(array_intersect_key($data, array_flip(['destination_id', 'service_point_id', 'place_id'])))) {
            if ($trip->stops()->where('route_id', $data['route_id'])->whereIn('route_part', ['route', 'stretch'])->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['route_id' => ['This route, or part of it, is already on this trip.']]);
            }
            $data['route_part'] = 'route';
        }
        if ($part === 'stops' && ! isset($data['position'])) {
            $data['position'] = (int) $trip->stops()->max('position') + 1;
        }
        $this->relation($trip, $part)->create($data);
        $trip->touch();

        return new TripResource($trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']));
    }

    public function update(Request $request, Trip $trip, string $part, string $id): TripResource
    {
        $this->authorize('edit', $trip);
        // Looked up through the trip, so an id from another trip is simply not found (no IDOR).
        $row = $this->relation($trip, $part)->whereKey($id)->firstOrFail();
        $data = $this->clean($trip, $request->validate($this->rules($part, true)), $part, $row);
        // A route, stretch or checkpoint stop keeps its links; name, day, overnight and note stay editable.
        if ($part === 'stops' && $row->route_part && array_intersect_key($data, array_flip(['route_id', 'destination_id', 'service_point_id', 'place_id']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['route_id' => ['A route stop keeps its route. Remove it and add another instead.']]);
        }
        $row->update($data);
        $trip->touch();

        return new TripResource($trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']));
    }

    public function destroy(Trip $trip, string $part, string $id): TripResource
    {
        $this->authorize('edit', $trip);
        $this->relation($trip, $part)->whereKey($id)->firstOrFail()->delete();
        $trip->touch();

        return new TripResource($trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']));
    }

    /** "Paid by" must be a member of this trip. */
    private function clean(Trip $trip, array $data, string $part, ?\Illuminate\Database\Eloquent\Model $row = null): array
    {
        if ($part === 'expenses' && ($data['status'] ?? $row?->status ?? 'planned') === 'spent' && (array_key_exists('amount_npr', $data) ? $data['amount_npr'] : $row?->amount_npr) === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount_npr' => ['Enter the amount you spent.']]);
        }
        if (! empty($data['paid_by']) && ! $trip->members()->where('users.id', $data['paid_by'])->exists()) {
            abort(422, 'Paid by must be a member of this trip.');
        }

        return $data;
    }
}

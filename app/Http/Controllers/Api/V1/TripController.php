<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Personal and group trip workspaces. Access is by membership only. */
class TripController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TripResource::collection($request->user()->belongsToMany(Trip::class, 'trip_members')->orderByDesc('trips.updated_at')->get());
    }

    public function store(Request $request): TripResource
    {
        $trip = DB::transaction(function () use ($request) {
            $trip = new Trip($this->validated($request));
            $trip->owner_id = $request->user()->id;
            $trip->save();
            $trip->members()->attach($request->user()->id, ['role' => TripRole::Owner->value]);

            return $trip;
        });

        return new TripResource($this->full($trip));
    }

    public function show(Trip $trip): TripResource
    {
        $this->authorize('view', $trip);

        return new TripResource($this->full($trip));
    }

    public function update(Request $request, Trip $trip): TripResource
    {
        $this->authorize('edit', $trip);
        $trip->update($this->validated($request, true));

        return new TripResource($this->full($trip));
    }

    public function destroy(Trip $trip): JsonResponse
    {
        $this->authorize('manage', $trip);
        $trip->delete();

        return response()->json(null, 204);
    }

    public function addMember(Request $request, Trip $trip): TripResource
    {
        $this->authorize('manage', $trip);
        $data = $request->validate([
            'username' => ['required', 'string', 'exists:users,username'],
            'role' => ['required', Rule::in(['editor', 'viewer'])],
        ], ['username.exists' => 'No rider has that username.']);
        $user = User::where('username', $data['username'])->firstOrFail();
        if ($trip->roleOf($user)) {
            throw ValidationException::withMessages(['username' => ['Already a member.']]);
        }
        $trip->members()->attach($user->id, ['role' => $data['role']]);

        return new TripResource($this->full($trip));
    }

    public function updateMember(Request $request, Trip $trip, User $user): TripResource
    {
        $this->authorize('manage', $trip);
        $data = $request->validate(['role' => ['required', Rule::in(['editor', 'viewer'])]]);
        if ($trip->roleOf($user) === null || $trip->roleOf($user) === TripRole::Owner) {
            throw ValidationException::withMessages(['role' => ['Only other members can be changed.']]);
        }
        $trip->members()->updateExistingPivot($user->id, ['role' => $data['role']]);

        return new TripResource($this->full($trip));
    }

    /** Owners remove members; any member can leave. The owner cannot leave their own trip. */
    public function removeMember(Request $request, Trip $trip, User $user): JsonResponse
    {
        $self = $request->user()->id === $user->id;
        $self ? $this->authorize('view', $trip) : $this->authorize('manage', $trip);
        if ($trip->roleOf($user) === TripRole::Owner) {
            throw ValidationException::withMessages(['user' => ['The owner cannot leave. Delete the trip instead.']]);
        }
        $trip->members()->detach($user->id);

        return response()->json(null, 204);
    }

    private function full(Trip $trip): Trip
    {
        return $trip->refresh()->load(['members', 'stops', 'expenses', 'checklist']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'title' => [$partial ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'motorcycle' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}

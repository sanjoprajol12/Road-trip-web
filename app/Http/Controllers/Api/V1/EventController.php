<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventUpdate;
use App\Notifications\EventChanged;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Event::with(['organizer.verifications', 'route'])->where('visibility', 'public')
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Full->value, EventStatus::Postponed->value])
            ->where('ends_at', '>=', now())->orderBy('starts_at');
        $q->when($request->query('category'), fn ($q, $c) => $q->where('category', $c));

        return EventResource::collection($q->paginate(20));
    }

    public function show(Request $request, Event $event): EventResource
    {
        Gate::forUser($request->user())->authorize('view', $event);

        return new EventResource($event->load(['organizer.verifications', 'route', 'updates']));
    }

    /** Events I organize, and events I am registered for or invited to. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $with = ['organizer.verifications', 'route'];

        return response()->json([
            'organizing' => EventResource::collection(Event::with($with)->where('organizer_id', $user->id)->orderByDesc('starts_at')->get()),
            'participating' => EventResource::collection(Event::with($with)->whereHas('registrations', fn ($r) => $r->where('user_id', $user->id)
                ->whereIn('status', RegistrationStatus::involved()))->where('status', '!=', EventStatus::Draft->value)->orderByDesc('starts_at')->get()),
        ]);
    }

    public function store(Request $request): EventResource
    {
        $this->authorize('create', Event::class);
        $data = $this->validated($request);
        $event = DB::transaction(function () use ($request, $data) {
            $event = new Event($data);
            $event->organizer_id = $request->user()->id;
            $event->save();
            Audit::record('event.created', $event, null, $event->only(['title', 'status', 'visibility']));

            return $event;
        });

        return new EventResource($event->refresh()->load(['organizer.verifications', 'route', 'updates']));
    }

    public function update(Request $request, Event $event): EventResource
    {
        $this->authorize('manage', $event);
        if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Completed], true)) {
            throw ValidationException::withMessages(['event' => ['Cancelled and completed events cannot be edited.']]);
        }
        $data = $this->validated($request, $event);
        DB::transaction(function () use ($event, $data) {
            $event = Event::whereKey($event->id)->lockForUpdate()->first();
            if (isset($data['capacity']) && $data['capacity'] < $event->approvedCount()) {
                throw ValidationException::withMessages(['capacity' => ['Capacity cannot be lower than the riders already approved.']]);
            }
            $event->update($data);
            \App\Actions\Events\EventCapacity::settle($event);
        });

        return new EventResource($event->refresh()->load(['organizer.verifications', 'route', 'updates']));
    }

    public function changeStatus(Request $request, Event $event): EventResource
    {
        $this->authorize('manage', $event);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, $event->status->next()))],
            'message' => ['nullable', 'required_if:status,postponed,cancelled', 'string', 'min:10', 'max:2000'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ], ['status.in' => 'This event cannot move to that status now.', 'message.required_if' => 'Tell participants why (at least 10 characters).']);
        $to = EventStatus::from($data['status']);
        DB::transaction(function () use ($event, $to, $data) {
            $old = $event->status->value;
            $event->forceFill(['status' => $to, 'summary' => $to === EventStatus::Completed ? ($data['summary'] ?? $event->summary) : $event->summary])->save();
            if ($to === EventStatus::Published) {
                \App\Actions\Events\EventCapacity::settle($event);
            }
            Audit::record('event.'.$to->value, $event, ['status' => $old], ['status' => $to->value]);
            $kind = match ($to) { EventStatus::Postponed => 'postponement', EventStatus::Cancelled => 'cancellation', EventStatus::Completed => 'completion', default => null };
            if ($kind && ! empty($data['message'] ?? $data['summary'] ?? null)) {
                $this->announce($event, $kind, $data['message'] ?? $data['summary']);
            }
        });

        return new EventResource($event->refresh()->load(['organizer.verifications', 'route', 'updates']));
    }

    public function postUpdate(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manage', $event);
        $data = $request->validate(['body' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->announce($event, 'update', $data['body']);

        return response()->json(['message' => 'Update posted.'], 201);
    }

    private function announce(Event $event, string $kind, string $body): void
    {
        EventUpdate::create(['event_id' => $event->id, 'author_id' => auth()->id(), 'kind' => $kind, 'body' => $body]);
        $people = $event->registrations()->whereIn('status', [RegistrationStatus::Approved->value, RegistrationStatus::Pending->value, RegistrationStatus::Waitlisted->value, RegistrationStatus::Invited->value])
            ->with('user')->get()->pluck('user');
        Notification::send($people, new EventChanged($event, $kind, $body));
    }

    private function validated(Request $request, ?Event $event = null): array
    {
        $rule = $event ? 'sometimes' : 'required';

        return $request->validate([
            'category' => [$rule, Rule::in(['ride', 'gathering'])],
            'subtype' => [$rule, 'string', 'max:40'],
            'title' => [$rule, 'string', 'min:5', 'max:140'],
            'description' => [$rule, 'string', 'min:20', 'max:10000'],
            'starts_at' => [$rule, 'date', $event ? 'nullable' : 'after:now'],
            'ends_at' => [$rule, 'date', 'after:starts_at'],
            'meeting_point' => [$rule, 'string', 'min:3', 'max:200'],
            'route_id' => ['nullable', 'uuid', Rule::exists('routes', 'id')->where('status', 'published')],
            'visibility' => [$rule, Rule::in(['public', 'private'])],
            'join_method' => [$rule, Rule::in(['instant', 'approval'])],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
            'waitlist_enabled' => ['sometimes', 'boolean'],
        ]);
    }
}

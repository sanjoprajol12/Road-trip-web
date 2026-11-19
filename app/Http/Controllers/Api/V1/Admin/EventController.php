<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\EventUpdate;
use App\Notifications\EventChanged;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** Oversight: staff see every event and can cancel one (for example a revoked organizer's). */
class EventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Event::with(['organizer.verifications', 'route'])->orderByDesc('starts_at');
        $q->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        return EventResource::collection($q->paginate(50));
    }

    public function cancel(Request $request, Event $event): EventResource
    {
        $this->authorize('cancelAsStaff', $event);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        if (in_array($event->status, [EventStatus::Cancelled, EventStatus::Completed], true)) {
            throw ValidationException::withMessages(['event' => ['This event is already closed.']]);
        }
        DB::transaction(function () use ($event, $data) {
            $old = $event->status->value;
            $event->forceFill(['status' => EventStatus::Cancelled])->save();
            EventUpdate::create(['event_id' => $event->id, 'author_staff_id' => auth('staff')->id(), 'kind' => 'cancellation', 'body' => 'Cancelled by My Ride Nepal staff: '.$data['reason']]);
            Audit::record('event.cancelled_by_staff', $event, ['status' => $old], ['status' => 'cancelled', 'reason' => $data['reason']]);
        });
        $people = $event->registrations()->whereIn('status', ['approved', 'pending', 'waitlisted', 'invited'])->with('user')->get()->pluck('user');
        Notification::send($people, new EventChanged($event, 'cancellation', $data['reason']));

        return new EventResource($event->refresh()->load(['organizer.verifications', 'route', 'updates']));
    }
}

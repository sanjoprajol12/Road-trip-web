<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Events\EventRegistrations;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventRegistrationController extends Controller
{
    public function __construct(private EventRegistrations $registrations) {}

    public function join(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $reg = $this->registrations->join($event, $request->user());

        return response()->json(['status' => $reg->status->value, 'event_status' => $event->refresh()->status->value]);
    }

    public function leave(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $this->registrations->leave($event, $request->user());

        return response()->json(['status' => $event->registrationOf($request->user())?->status->value]);
    }

    /** The participant list: organizer only (private details never leave this endpoint). */
    public function index(Event $event): JsonResponse
    {
        $this->authorize('manage', $event);
        $rows = $event->registrations()->with('user')->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn (EventRegistration $r) => [
            'id' => $r->id, 'status' => $r->status->value, 'updated_at' => $r->updated_at?->toIso8601String(),
            'rider' => ['id' => $r->user->id, 'display_name' => $r->user->display_name, 'username' => $r->user->username],
        ])]);
    }

    public function decide(Request $request, Event $event, EventRegistration $registration): JsonResponse
    {
        $this->authorize('manage', $event);
        abort_unless($registration->event_id === $event->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'declined', 'attended', 'did_not_attend'])]]);
        $reg = $this->registrations->decide($event, $registration, RegistrationStatus::from($data['status']));

        return response()->json(['status' => $reg->status->value, 'event_status' => $event->refresh()->status->value]);
    }

    public function invite(Request $request, Event $event): JsonResponse
    {
        $this->authorize('manage', $event);
        $data = $request->validate(['username' => ['required', 'string', 'exists:users,username']], ['username.exists' => 'No rider has that username.']);
        $this->registrations->invite($event, User::where('username', $data['username'])->firstOrFail());

        return response()->json(['message' => 'Invitation sent.'], 201);
    }
}

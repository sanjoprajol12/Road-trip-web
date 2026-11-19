<?php

namespace App\Actions\Events;

use App\Enums\RegistrationStatus as S;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventInvitation;
use App\Notifications\RegistrationChanged;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Joining, leaving, inviting and deciding. Every path locks the event row first. */
class EventRegistrations
{
    public function join(Event $event, User $user): EventRegistration
    {
        return DB::transaction(function () use ($event, $user) {
            $event = EventCapacity::lock($event);
            if (! $event->status->acceptsRegistrations()) {
                throw ValidationException::withMessages(['event' => ['This event is not taking registrations.']]);
            }
            if ($event->organizer_id === $user->id) {
                throw ValidationException::withMessages(['event' => ['You are hosting this event.']]);
            }
            $reg = $event->registrationOf($user);
            $current = $reg?->status;
            if ($current && ! in_array($current, [S::Invited, S::Cancelled, S::Declined], true)) {
                throw ValidationException::withMessages(['event' => ['You are already registered.']]);
            }
            if ($current === S::Declined && $event->join_method === 'approval') {
                throw ValidationException::withMessages(['event' => ['The organizer declined your request.']]);
            }
            if ($event->visibility === 'private' && $current !== S::Invited) {
                throw ValidationException::withMessages(['event' => ['This is a private event. You need an invitation.']]);
            }
            // An invitation counts as approval by the organizer; otherwise the event's join method decides.
            $status = ($current === S::Invited || $event->join_method === 'instant')
                ? (EventCapacity::hasRoom($event) ? S::Approved : ($event->waitlist_enabled ? S::Waitlisted : null))
                : S::Pending;
            if ($status === null) {
                throw ValidationException::withMessages(['event' => ['This event is full.']]);
            }
            $reg = EventRegistration::updateOrCreate(['event_id' => $event->id, 'user_id' => $user->id], ['status' => $status]);
            EventCapacity::settle($event);

            return $reg->refresh();
        });
    }

    public function leave(Event $event, User $user): void
    {
        DB::transaction(function () use ($event, $user) {
            $event = EventCapacity::lock($event);
            $reg = $event->registrationOf($user);
            if (! $reg || ! in_array($reg->status, [S::Invited, S::Pending, S::Approved, S::Waitlisted], true)) {
                throw ValidationException::withMessages(['event' => ['You are not registered for this event.']]);
            }
            $reg->update(['status' => $reg->status === S::Invited ? S::Declined : S::Cancelled]);
            EventCapacity::settle($event);
        });
    }

    public function invite(Event $event, User $invitee): EventRegistration
    {
        $reg = DB::transaction(function () use ($event, $invitee) {
            $event = EventCapacity::lock($event);
            if ($invitee->id === $event->organizer_id) {
                throw ValidationException::withMessages(['username' => ['You cannot invite yourself.']]);
            }
            $reg = $event->registrationOf($invitee);
            if ($reg && ! in_array($reg->status, [S::Cancelled, S::Declined], true)) {
                throw ValidationException::withMessages(['username' => ['This rider is already on the list.']]);
            }
            $reg = EventRegistration::updateOrCreate(['event_id' => $event->id, 'user_id' => $invitee->id], ['status' => S::Invited]);
            Audit::record('event.invited', $reg, null, ['event_id' => $event->id, 'user_id' => $invitee->id]);

            return $reg;
        });
        $invitee->notify(new EventInvitation($event));

        return $reg;
    }

    /** Organizer decisions on a registration. */
    public function decide(Event $event, EventRegistration $reg, S $to): EventRegistration
    {
        return DB::transaction(function () use ($event, $reg, $to) {
            $event = EventCapacity::lock($event);
            $reg = EventRegistration::whereKey($reg->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($to) {
                S::Approved => in_array($reg->status, [S::Pending, S::Waitlisted], true),
                // Approved riders can be removed before the start; their place goes to the waitlist (settle).
                S::Declined => in_array($reg->status, [S::Pending, S::Waitlisted], true) || ($reg->status === S::Approved && $event->starts_at->isFuture()),
                S::Attended, S::DidNotAttend => in_array($reg->status, [S::Approved, S::Attended, S::DidNotAttend], true) && $event->starts_at->isPast(),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => ["Cannot change a {$reg->status->value} registration to {$to->value}."]]);
            }
            if ($to === S::Approved && ! EventCapacity::hasRoom($event)) {
                throw ValidationException::withMessages(['status' => ['The event is full. Raise the capacity or wait for a place.']]);
            }
            $wasApproved = $reg->status === S::Approved;
            $reg->update(['status' => $to]);
            EventCapacity::settle($event);
            if ($to === S::Approved || $to === S::Declined) {
                $reg->user->notify(new RegistrationChanged($event, $to === S::Declined && $wasApproved ? 'removed' : $to->value));
            }

            return $reg;
        });
    }
}

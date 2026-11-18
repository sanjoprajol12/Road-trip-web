<?php

namespace App\Policies;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Enums\VerificationKind;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /** Only verified organizers host. Read live, so suspension or revocation stops hosting at once. */
    public function create(User $user): bool
    {
        return $user->hasVerification(VerificationKind::EventOrganizer) && ! $user->isSuspended();
    }

    public function view(?User $user, Event $event): bool
    {
        if ($event->visibility === 'public' && $event->status !== EventStatus::Draft) {
            return true;
        }
        if (! $user) {
            return false;
        }
        if ($event->organizer_id === $user->id || $user->isStaff()) {
            return true;
        }
        $reg = $event->registrationOf($user);

        return $event->status !== EventStatus::Draft && $reg && in_array($reg->status->value, RegistrationStatus::involved(), true);
    }

    public function manage(User $user, Event $event): bool
    {
        return $event->organizer_id === $user->id && $this->create($user);
    }

    /** Staff can cancel any event (oversight) but do not run other people's events. */
    public function cancelAsStaff(User $user, Event $event): bool
    {
        return $user->isStaff();
    }
}

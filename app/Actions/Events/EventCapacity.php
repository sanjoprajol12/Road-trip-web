<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Event;

/**
 * Capacity bookkeeping. Always called inside a transaction that holds the event row lock
 * (Event::lockForUpdate), so two riders can never take the last place.
 */
class EventCapacity
{
    public static function hasRoom(Event $event): bool
    {
        return $event->capacity === null || $event->approvedCount() < $event->capacity;
    }

    /** Fill freed places from the waitlist (oldest first), then set or clear "full". */
    public static function settle(Event $event): void
    {
        while (self::hasRoom($event)) {
            $next = $event->registrations()->where('status', RegistrationStatus::Waitlisted->value)->orderBy('updated_at')->first();
            if (! $next) {
                break;
            }
            $next->update(['status' => RegistrationStatus::Approved]);
        }
        $full = ! self::hasRoom($event);
        if ($full && $event->status === EventStatus::Published) {
            $event->forceFill(['status' => EventStatus::Full])->save();
        } elseif (! $full && $event->status === EventStatus::Full) {
            $event->forceFill(['status' => EventStatus::Published])->save();
        }
    }

    public static function lock(Event $event): Event
    {
        return Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
    }
}

<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Notifications\Notification;

/**
 * Tells a rider their place on an event changed: a waitlist place opened (promoted), or the organizer
 * approved or declined their request. In-app only, like the other event notifications.
 */
class RegistrationChanged extends Notification
{
    /** @param 'promoted'|'approved'|'declined'|'removed' $change */
    public function __construct(public Event $event, public string $change) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'event_registration', 'change' => $this->change, 'event_id' => $this->event->id, 'title' => $this->event->title, 'link' => '/events/'.$this->event->id];
    }
}

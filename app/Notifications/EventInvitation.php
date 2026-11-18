<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Notifications\Notification;

class EventInvitation extends Notification
{
    public function __construct(public Event $event) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'event_invitation', 'event_id' => $this->event->id, 'title' => $this->event->title, 'link' => '/events/'.$this->event->id];
    }
}

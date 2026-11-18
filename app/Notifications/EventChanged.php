<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Notifications\Notification;

/** Sent to everyone still taking part when the organizer posts an update, postponement or cancellation. */
class EventChanged extends Notification
{
    public function __construct(public Event $event, public string $updateKind, public string $body) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'event_'.$this->updateKind, 'event_id' => $this->event->id, 'title' => $this->event->title, 'body' => $this->body, 'link' => '/events/'.$this->event->id];
    }
}

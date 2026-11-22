<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A group trip was deleted because its owner deleted their account and no member took it over. Title only. */
class TripClosed extends Notification
{
    public function __construct(public string $title) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'trip_closed', 'title' => $this->title, 'body' => 'The owner deleted their account and nobody took the trip over, so it was deleted.', 'link' => '/rides'];
    }
}

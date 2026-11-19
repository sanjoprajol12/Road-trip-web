<?php

namespace App\Notifications;

use App\Models\Trip;
use Illuminate\Notifications\Notification;

/** A rider was invited to a trip. In-app only; the trip stays private until they accept. */
class TripInvited extends Notification
{
    public function __construct(public Trip $trip, public string $by) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'trip_invitation', 'title' => $this->trip->title, 'by' => $this->by, 'link' => '/rides'];
    }
}

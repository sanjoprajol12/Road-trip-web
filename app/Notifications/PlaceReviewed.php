<?php

namespace App\Notifications;

use App\Models\Place;
use Illuminate\Notifications\Notification;

/** A rider's suggested place was published, sent back for changes, or rejected. In-app only; the reviewer is not named. */
class PlaceReviewed extends Notification
{
    public function __construct(public Place $place, public string $outcome) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'place_reviewed', 'change' => $this->outcome, 'title' => $this->place->name, 'link' => '/places/suggest'];
    }
}

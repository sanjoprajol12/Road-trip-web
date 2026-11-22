<?php

namespace App\Notifications;

use App\Models\OwnershipTransfer;
use Illuminate\Notifications\Notification;

/** In-app: an offer to take over an event or a trip (to the recipient), or the answer to it (to the rider leaving). */
class OwnershipOffer extends Notification
{
    public function __construct(public OwnershipTransfer $transfer, public string $title, public string $stage, public string $by) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'ownership_'.$this->stage, 'subject' => $this->transfer->subject_type, 'title' => $this->title, 'by' => $this->by, 'link' => '/account#offers'];
    }
}

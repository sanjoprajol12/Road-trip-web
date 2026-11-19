<?php

namespace App\Notifications;

use App\Models\Contribution;
use Illuminate\Notifications\Notification;

/** A moderator asks the author to add a correction. In-app only; the reviewer is not named. */
class ContributionCorrectionRequested extends Notification
{
    public function __construct(public Contribution $contribution) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'contribution_correction', 'contribution_id' => $this->contribution->id, 'title' => $this->contribution->title, 'link' => '/experiences/'.$this->contribution->id];
    }
}

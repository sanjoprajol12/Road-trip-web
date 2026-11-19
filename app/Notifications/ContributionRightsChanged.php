<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Contribution rights paused, restored, or an appeal decided. In-app only; the reviewer is not named. */
class ContributionRightsChanged extends Notification
{
    /** @param 'suspended'|'lifted'|'appeal_upheld' $change */
    public function __construct(public string $change, public ?string $until = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'contribution_rights', 'change' => $this->change, 'until' => $this->until, 'link' => '/account#activity'];
    }
}

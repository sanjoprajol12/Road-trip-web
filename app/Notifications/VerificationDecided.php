<?php

namespace App\Notifications;

use App\Models\Verification;
use Illuminate\Notifications\Notification;

class VerificationDecided extends Notification
{
    public function __construct(public Verification $claim) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'verification_decided',
            'claim_kind' => $this->claim->kind->value,
            'status' => $this->claim->status->value,
            'note' => $this->claim->review_note,
            'link' => '/account',
        ];
    }
}

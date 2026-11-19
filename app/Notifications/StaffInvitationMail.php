<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to an email address (not yet necessarily an account) that staff invited to the staff app. */
class StaffInvitationMail extends Notification
{
    /** @param list<string> $roleNames */
    public function __construct(public string $token, public string $email, public array $roleNames, public string $inviterName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('mrn.admin_url').'/accept-invitation?'.http_build_query(['token' => $this->token, 'email' => $this->email]);

        return (new MailMessage)
            ->subject('You are invited to the My Ride Nepal staff app')
            ->line("{$this->inviterName} invited you to join My Ride Nepal staff as: ".implode(', ', $this->roleNames).'.')
            ->action('Accept the invitation', $url)
            ->line('The link works for '.config('mrn.invitation_hours').' hours and only once. If you did not expect this, ignore this email.');
    }
}

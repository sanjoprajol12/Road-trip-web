<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Staff password links open the staff portal (ADMIN_URL), never the public website. */
class StaffPasswordReset extends Notification
{
    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('mrn.admin_url').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.staff.expire');

        return (new MailMessage)
            ->subject('Set your My Ride Nepal staff password')
            ->line('Use this link to set a new password for your **staff** account. Your rider account, if you have one, is not affected.')
            ->action('Set staff password', $url)
            ->line("The link works once and expires in {$minutes} minutes. If you did not ask for it, you can ignore this email.");
    }
}

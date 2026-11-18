<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Sign-up confirmation. The signed link confirms the address and signs the rider in. */
class VerifyEmailAddress extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('api.auth.verify-email', now()->addHours(24), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);

        return (new MailMessage)
            ->subject('Confirm your email for My Ride Nepal')
            ->line('Open this link to finish creating your account. It works for 24 hours.')
            ->action('Confirm email', $url)
            ->line('If you did not sign up, ignore this email.');
    }
}

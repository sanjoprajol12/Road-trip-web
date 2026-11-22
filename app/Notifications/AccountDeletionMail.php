<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails about the rider's own deletion request: scheduled, cancelled, or done (sent to the old address after the deletion). */
class AccountDeletionMail extends Notification
{
    public function __construct(public string $kind, public ?\DateTimeInterface $date = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = rtrim((string) config('app.url'), '/').'/account#delete';

        return match ($this->kind) {
            'scheduled' => (new MailMessage)->subject('Your My Ride Nepal account will be deleted on '.$this->date->format('j M Y'))
                ->line('You asked us to delete your account. It will be deleted on '.$this->date->format('j M Y').', with the choices you made for your contributions.')
                ->line('Until then you can sign in to download your data or cancel the deletion. Signing in alone does not cancel it.')
                ->action('Open your account', $account)
                ->line('If you did not ask for this, sign in, cancel the deletion and change your password.'),
            'cancelled' => (new MailMessage)->subject('Your account deletion is cancelled')
                ->line('You cancelled the deletion of your My Ride Nepal account. Nothing was deleted, and you can use your account again.')
                ->line('Any event or trip that another rider already accepted stays with them.'),
            default => (new MailMessage)->subject('Your My Ride Nepal account is deleted')
                ->line('Your account and its personal data are deleted, with the choices you made for your contributions.')
                ->line('Backups expire on their own schedule. This is the last email we send to this address.'),
        };
    }
}

<?php

namespace App\Support;

/**
 * Applies the staff mail settings (when "override" is on) to Laravel's mail configuration. Called when the mail
 * manager is first resolved, so a request or queue job uses the values current at that time. The password is
 * decrypted only here and never logged.
 */
final class MailSettings
{
    public static function apply(): void
    {
        try {
            if (! Settings::get('mail.override')) {
                return;
            }
            $enc = Settings::get('mail.encryption');
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => Settings::get('mail.host'),
                'mail.mailers.smtp.port' => Settings::get('mail.port'),
                'mail.mailers.smtp.scheme' => $enc === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => Settings::get('mail.username'),
                'mail.mailers.smtp.password' => Settings::get('mail.password'),
                'mail.from.address' => Settings::get('mail.from_address'),
                'mail.from.name' => Settings::get('mail.from_name'),
            ]);
        } catch (\Throwable) {
            // Settings unreadable (no database yet): keep the .env mail configuration.
        }
    }
}

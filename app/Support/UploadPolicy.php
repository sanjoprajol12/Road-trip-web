<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The one decision on whether a new file may be stored (docs/architecture/UPLOAD_SAFETY_DESIGN.md).
 * Reading existing files never asks this class. Order of checks: environment switch, staff setting, the chosen disk
 * is configured, and the local disk only in development and testing (owner decision O6). Nothing ever falls back to
 * another disk.
 */
final class UploadPolicy
{
    public const DISABLED_BY_ENVIRONMENT = 'disabled_by_environment';

    public const DISABLED_BY_SETTING = 'disabled_by_setting';

    public const STORAGE_NOT_CONFIGURED = 'storage_not_configured';

    public const STORAGE_NOT_PERSISTENT = 'storage_not_persistent';

    public const STORAGE_WRITE_FAILED = 'storage_write_failed';

    /** @return array{allowed: bool, reason: ?string, disk: string, driver: ?string} */
    public static function status(): array
    {
        $disk = (string) Settings::get('storage.driver');
        $driver = config("filesystems.disks.{$disk}.driver");
        $reason = match (true) {
            ! config('mrn.uploads.enabled') => self::DISABLED_BY_ENVIRONMENT,
            ! Settings::get('storage.uploads_enabled') => self::DISABLED_BY_SETTING,
            ! self::configured($disk) => self::STORAGE_NOT_CONFIGURED,
            $driver === 'local' && ! app()->environment(config('mrn.uploads.local_disk_environments')) => self::STORAGE_NOT_PERSISTENT,
            default => null,
        };

        return ['allowed' => $reason === null, 'reason' => $reason, 'disk' => $disk, 'driver' => is_string($driver) ? $driver : null];
    }

    /** The disk to write to, or a 422 on the upload field with the message staff set. Storage faults are alerted. */
    public static function ensureAllowed(string $field): string
    {
        $s = self::status();
        if (! $s['allowed']) {
            if (in_array($s['reason'], [self::STORAGE_NOT_CONFIGURED, self::STORAGE_NOT_PERSISTENT], true)) {
                self::alert($s['reason'], $s['disk']);
            }
            throw ValidationException::withMessages([$field => [self::message()]]);
        }

        return $s['disk'];
    }

    public static function message(): string
    {
        return (string) Settings::get('storage.uploads_paused_message');
    }

    /**
     * Critical operational event: reason, disk name, driver and environment only (no file names, user data or
     * credentials). At most once an hour per reason, so a busy form cannot flood the log.
     */
    public static function alert(string $reason, string $disk): void
    {
        if (Cache::add("uploads-alert:{$reason}", 1, now()->addHour())) {
            Log::critical('Uploads refused: storage is not usable for new files.', [
                'reason' => $reason, 'disk' => $disk, 'driver' => config("filesystems.disks.{$disk}.driver"), 'environment' => app()->environment(),
            ]);
        }
    }

    /** The disk exists in config/filesystems.php; an S3-compatible disk also needs a bucket and credentials. */
    private static function configured(string $disk): bool
    {
        $c = config("filesystems.disks.{$disk}");
        if (! is_array($c) || empty($c['driver'])) {
            return false;
        }

        return $c['driver'] !== 's3' || (filled($c['bucket'] ?? null) && filled($c['key'] ?? null) && filled($c['secret'] ?? null));
    }
}

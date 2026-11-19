<?php

namespace App\Support;

/**
 * Short-lived signed tokens that let staff look at the public website as it will be: through maintenance mode now,
 * and at draft CMS content later. HMAC-SHA256 with the app key; nothing is stored. A token carries the staff
 * account id, a scope and an expiry, so it cannot be widened or extended without the key.
 */
final class PreviewToken
{
    public const HEADER = 'X-Preview-Token';

    public static function issue(string $staffId, string $scope = 'site', int $minutes = 30): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['s' => $staffId, 'c' => $scope, 'e' => now()->addMinutes($minutes)->timestamp])), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload);
    }

    /** The payload of a valid, unexpired token for this scope, or null. */
    public static function verify(?string $token, string $scope = 'site'): ?array
    {
        if (! $token || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token);
        if (! hash_equals(self::sign($payload), $sig)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && ($data['c'] ?? null) === $scope && ($data['e'] ?? 0) > now()->timestamp ? $data : null;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'mrn-preview|'.$payload, (string) config('app.key'));
    }
}

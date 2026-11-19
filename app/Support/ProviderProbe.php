<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * One harmless request to a configured geocoding or routing provider, so staff see whether it answers. Nothing is
 * stored. URLs are staff input, so private, loopback and reserved addresses are refused (no server-side request forgery).
 */
final class ProviderProbe
{
    public static function run(string $which): array
    {
        $provider = Settings::get("maps.{$which}_provider");
        $base = rtrim((string) Settings::get("maps.{$which}_url"), '/');
        $key = Settings::get("maps.{$which}_key");
        if ($provider === 'none' || ! $base) {
            return ['ok' => false, 'status' => null, 'message' => 'No provider is configured.'];
        }
        if (! self::publicHost((string) parse_url($base, PHP_URL_HOST))) {
            return ['ok' => false, 'status' => null, 'message' => 'The provider address must be a public internet host.'];
        }
        // Pokhara to Kathmandu, a fixed and harmless sample.
        [$url, $headers] = match ($provider) {
            'nominatim' => ["{$base}/search?q=Pokhara&format=json&limit=1", []],
            'osrm' => ["{$base}/route/v1/driving/83.9856,28.2096;85.3240,27.7172?overview=false", []],
            'openrouteservice' => ["{$base}/v2/directions/driving-car?start=83.9856,28.2096&end=85.3240,27.7172", $key ? ['Authorization' => $key] : []],
        };
        try {
            $r = Http::timeout(8)->withoutRedirecting()->withHeaders($headers + ['User-Agent' => 'MyRideNepal-server/1.0'])->get($url);

            return ['ok' => $r->successful(), 'status' => $r->status(), 'message' => $r->successful() ? 'The provider answered.' : "The provider answered with HTTP {$r->status()}."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'message' => 'No answer: '.class_basename($e).'.'];
        }
    }

    private static function publicHost(string $host): bool
    {
        $ips = $host === '' ? [] : (gethostbynamel($host) ?: []);

        return $ips !== [] && collect($ips)->every(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }
}

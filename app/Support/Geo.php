<?php

namespace App\Support;

/**
 * Straight-line geometry on latitude/longitude pairs, without PostGIS (owner decision D3 pending).
 * Distances are "as the crow flies" from the recorded route line, never riding distance or detour time.
 * shortcut: equirectangular projection per segment, fine for Nepal-scale distances (< 200 km); switch to PostGIS
 * ST_Distance on geography columns once D3 is approved.
 */
final class Geo
{
    private const EARTH_KM = 6371.0088;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_KM * asin(min(1, sqrt($a)));
    }

    /** Shortest distance in km from a point to the line from A to B. */
    public static function pointToSegmentKm(float $lat, float $lng, float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $k = cos(deg2rad(($aLat + $bLat + $lat) / 3));
        [$px, $py] = [($lng - $aLng) * $k, $lat - $aLat];
        [$bx, $by] = [($bLng - $aLng) * $k, $bLat - $aLat];
        $len2 = $bx * $bx + $by * $by;
        $t = $len2 > 0 ? max(0, min(1, ($px * $bx + $py * $by) / $len2)) : 0;

        return self::haversineKm($lat, $lng, $aLat + $t * ($bLat - $aLat), $aLng + $t * ($bLng - $aLng));
    }
}

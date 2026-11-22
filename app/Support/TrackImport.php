<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Validation\ValidationException;

/**
 * Reads a recorded track (GPX trkpt/rtept, or a GeoJSON LineString / MultiLineString / Feature / FeatureCollection)
 * into [[lat, lng], ...]. Every point must lie in Nepal; 2 to 5000 points. External XML entities are never loaded.
 */
final class TrackImport
{
    public const MAX_POINTS = 5000;

    /** @return list<array{0: float, 1: float}> */
    public static function parse(string $contents, string $extension): array
    {
        $points = strtolower($extension) === 'gpx' ? self::gpx($contents) : self::geojson($contents);
        if (count($points) < 2) {
            self::fail('The file has fewer than two track points.');
        }
        if (count($points) > self::MAX_POINTS) {
            self::fail('The track has more than '.self::MAX_POINTS.' points. Simplify it before importing.');
        }
        foreach ($points as [$lat, $lng]) {
            if ($lat < Place::LAT[0] || $lat > Place::LAT[1] || $lng < Place::LNG[0] || $lng > Place::LNG[1]) {
                self::fail("A point ({$lat}, {$lng}) lies outside Nepal. Check the file (latitude and longitude may be swapped).");
            }
        }

        return array_map(fn ($p) => [round($p[0], 6), round($p[1], 6)], $points);
    }

    private static function gpx(string $xml): array
    {
        if (str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            self::fail('GPX files with a DOCTYPE are not accepted.');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            self::fail('The GPX file could not be read.');
        }
        $nodes = $doc->xpath('//*[local-name()="trkpt"]') ?: $doc->xpath('//*[local-name()="rtept"]') ?: [];

        return array_map(fn ($n) => [(float) $n['lat'], (float) $n['lon']], $nodes);
    }

    private static function geojson(string $json): array
    {
        $g = json_decode($json, true);
        if (! is_array($g)) {
            self::fail('The GeoJSON file could not be read.');
        }
        $lines = [];
        $collect = function (array $geom) use (&$collect, &$lines) {
            match ($geom['type'] ?? null) {
                'FeatureCollection' => array_map(fn ($f) => $collect($f), $geom['features'] ?? []),
                'Feature' => $collect($geom['geometry'] ?? []),
                'LineString' => $lines[] = $geom['coordinates'] ?? [],
                'MultiLineString' => array_push($lines, ...($geom['coordinates'] ?? [])),
                default => null,
            };
        };
        $collect($g);
        $points = [];
        foreach ($lines as $line) {
            foreach ($line as $c) {
                if (is_array($c) && count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                    $points[] = [(float) $c[1], (float) $c[0]]; // GeoJSON is [longitude, latitude]
                }
            }
        }

        return $points;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['track' => [$message]]);
    }
}

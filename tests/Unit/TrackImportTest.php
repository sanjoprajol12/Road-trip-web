<?php

namespace Tests\Unit;

use App\Support\TrackImport;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrackImportTest extends TestCase
{
    public function test_gpx_and_geojson_are_read_in_lat_lng_order(): void
    {
        $gpx = '<?xml version="1.0"?><gpx xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="28.0" lon="84.0"/><trkpt lat="28.1" lon="84.2"/></trkseg></trk></gpx>';
        $this->assertSame([[28.0, 84.0], [28.1, 84.2]], TrackImport::parse($gpx, 'gpx'));
        $geo = json_encode(['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[84.0, 28.0], [84.2, 28.1]]]]);
        $this->assertSame([[28.0, 84.0], [28.1, 84.2]], TrackImport::parse($geo, 'geojson'));
    }

    public function test_bad_tracks_are_refused(): void
    {
        foreach ([
            ['{"type":"LineString","coordinates":[[84,28]]}', 'geojson'], // one point
            ['{"type":"LineString","coordinates":[[28,84],[28.1,84.1]]}', 'geojson'], // swapped: outside Nepal
            ['<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><gpx><trk><trkseg><trkpt lat="28" lon="84"/><trkpt lat="28.1" lon="84.1"/></trkseg></trk></gpx>', 'gpx'], // XXE attempt
            ['not xml', 'gpx'],
        ] as [$body, $ext]) {
            try {
                TrackImport::parse($body, $ext);
                $this->fail("Accepted: {$body}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

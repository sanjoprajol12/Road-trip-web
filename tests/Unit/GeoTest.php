<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_haversine_matches_a_known_distance(): void
    {
        // One degree of latitude is about 111.2 km.
        $this->assertEqualsWithDelta(111.2, Geo::haversineKm(27.0, 85.0, 28.0, 85.0), 0.2);
        $this->assertSame(0.0, Geo::haversineKm(27.7, 85.3, 27.7, 85.3));
    }

    public function test_point_to_segment_uses_the_nearest_point_on_the_line(): void
    {
        // A west-east line along latitude 28; a point 0.1 degree north of its middle is about 11.1 km away.
        $this->assertEqualsWithDelta(11.12, Geo::pointToSegmentKm(28.1, 84.5, 28.0, 84.0, 28.0, 85.0), 0.05);
        // Beyond the end, the distance is to the end point, not to the infinite line.
        $this->assertEqualsWithDelta(Geo::haversineKm(28.0, 85.5, 28.0, 85.0), Geo::pointToSegmentKm(28.0, 85.5, 28.0, 84.0, 28.0, 85.0), 0.01);
        // A zero-length segment is a point.
        $this->assertEqualsWithDelta(Geo::haversineKm(28.0, 85.0, 27.0, 85.0), Geo::pointToSegmentKm(28.0, 85.0, 27.0, 85.0, 27.0, 85.0), 0.01);
    }
}

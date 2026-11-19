<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** L1, L5, L6, L13: a destination with coordinates lists what is near it (straight-line), published items only. */
class DestinationNearbyTest extends TestCase
{
    use RefreshDatabase;

    public function test_nearby_destinations_services_and_routes(): void
    {
        $here = Destination::create(['slug' => 'test-here', 'name' => 'Test here', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 84.0]);
        Destination::create(['slug' => 'test-close', 'name' => 'Test close', 'status' => 'published', 'latitude' => 28.1, 'longitude' => 84.0]); // ~11 km
        Destination::create(['slug' => 'test-far', 'name' => 'Test far', 'status' => 'published', 'latitude' => 29.0, 'longitude' => 84.0]); // ~111 km
        Destination::create(['slug' => 'test-draft', 'name' => 'Test draft', 'status' => 'draft', 'latitude' => 28.01, 'longitude' => 84.0]);
        ServicePoint::create(['kind' => 'fuel', 'name' => 'Attached pump', 'destination_id' => $here->id, 'latitude' => 28.0, 'longitude' => 84.0, 'status' => 'published']);
        $close = Destination::where('slug', 'test-close')->first();
        ServicePoint::create(['kind' => 'mechanic', 'name' => 'Nearby workshop', 'destination_id' => $close->id, 'latitude' => 28.05, 'longitude' => 84.0, 'status' => 'published']); // ~5.6 km
        ServicePoint::create(['kind' => 'medical', 'name' => 'Draft clinic', 'destination_id' => $close->id, 'latitude' => 28.02, 'longitude' => 84.0, 'status' => 'draft']);
        $passing = RideRoute::create(['slug' => 'test-passing', 'name' => 'Passing road', 'status' => 'published']);
        $passing->segments()->create(['position' => 1, 'start_name' => 'West', 'end_name' => 'East', 'surface' => 'paved', 'start_lat' => 28.05, 'start_lng' => 83.5, 'end_lat' => 28.05, 'end_lng' => 84.5]);
        $remote = RideRoute::create(['slug' => 'test-remote', 'name' => 'Remote road', 'status' => 'published']);
        $remote->segments()->create(['position' => 1, 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved', 'start_lat' => 29.5, 'start_lng' => 83.5, 'end_lat' => 29.5, 'end_lng' => 84.5]);

        $res = $this->getJson('/api/v1/destinations/test-here')->assertOk()
            ->assertJsonPath('data.latitude', 28)->assertJsonPath('nearby.radius_km.destinations', 50);
        $this->assertSame(['Test close'], collect($res->json('nearby.destinations'))->pluck('name')->all());
        $this->assertEqualsWithDelta(11.1, $res->json('nearby.destinations.0.distance_km'), 0.2);
        $this->assertSame(['Nearby workshop'], collect($res->json('nearby.services'))->pluck('name')->all()); // attached one is under "services"
        $this->assertSame('Test close', $res->json('nearby.services.0.place'));
        $this->assertSame(['Passing road'], collect($res->json('nearby.routes'))->pluck('name')->all());
        $this->assertEqualsWithDelta(5.6, $res->json('nearby.routes.0.distance_km'), 0.2);
    }

    public function test_no_coordinates_means_no_nearby_section(): void
    {
        Destination::create(['slug' => 'test-nowhere', 'name' => 'Test nowhere', 'status' => 'published']);
        $this->getJson('/api/v1/destinations/test-nowhere')->assertOk()->assertJsonPath('nearby', null);
    }
}

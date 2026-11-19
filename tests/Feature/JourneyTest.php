<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Explore Along My Route, curated mode: routes between two places and what lies near them. */
class JourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_between_two_places_with_services_on_and_near_the_line(): void
    {
        $a = Destination::create(['slug' => 'town-a', 'name' => 'Town A', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 84.0]);
        $b = Destination::create(['slug' => 'town-b', 'name' => 'Town B', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 85.0]);
        Destination::create(['slug' => 'lake-c', 'name' => 'Lake C', 'status' => 'published', 'latitude' => 28.05, 'longitude' => 84.5]); // about 5.6 km off the line
        Destination::create(['slug' => 'far-d', 'name' => 'Far D', 'status' => 'published', 'latitude' => 28.5, 'longitude' => 84.5]); // about 55 km
        Destination::create(['slug' => 'draft-e', 'name' => 'Draft E', 'status' => 'draft', 'latitude' => 28.0, 'longitude' => 84.5]);
        // Recorded from B to A: a rider asking A to B gets it marked as reversed.
        $r = RideRoute::create(['slug' => 'b-to-a', 'name' => 'B to A road', 'status' => 'published', 'start_destination_id' => $b->id, 'end_destination_id' => $a->id]);
        $seg = $r->segments()->create(['position' => 1, 'start_name' => 'Town B', 'end_name' => 'Town A', 'surface' => 'paved', 'start_lat' => 28.0, 'start_lng' => 85.0, 'end_lat' => 28.0, 'end_lng' => 84.0]);
        ServicePoint::create(['kind' => 'fuel', 'name' => 'On-road pump', 'route_segment_id' => $seg->id, 'status' => 'published']);
        ServicePoint::create(['kind' => 'mechanic', 'name' => 'Side workshop', 'destination_id' => $a->id, 'latitude' => 28.02, 'longitude' => 84.3, 'status' => 'published']);
        ServicePoint::create(['kind' => 'medical', 'name' => 'Draft clinic', 'destination_id' => $a->id, 'latitude' => 28.0, 'longitude' => 84.3, 'status' => 'draft']);
        RideRoute::create(['slug' => 'unrelated', 'name' => 'Unrelated', 'status' => 'published', 'start_destination_id' => $a->id]);

        $res = $this->getJson('/api/v1/journeys?from=town-a&to=town-b')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.route.slug', 'b-to-a')->assertJsonPath('data.0.reversed', true);
        $places = collect($res->json('data.0.places'));
        $this->assertSame(['On-road pump', 'Side workshop', 'Lake C'], $places->pluck('name')->all());
        $this->assertTrue($places[0]['on_route']);
        $this->assertFalse($places[1]['on_route']);
        $this->assertEqualsWithDelta(2.2, $places[1]['distance_km'], 0.2);
        $this->assertEqualsWithDelta(5.6, $places[2]['distance_km'], 0.2);
        $this->assertSame('/explore/lake-c', $places[2]['link']);

        // A wider radius reaches further; nothing hidden ever appears.
        $wide = collect($this->getJson('/api/v1/journeys?from=town-a&to=town-b&within_km=50')->json('data.0.places'))->pluck('name');
        $this->assertNotContains('Far D', $wide);
        $this->assertNotContains('Draft E', $wide);
        $this->assertNotContains('Draft clinic', $wide);
    }

    public function test_validation_and_unknown_places(): void
    {
        Destination::create(['slug' => 'town-a', 'name' => 'Town A', 'status' => 'published']);
        Destination::create(['slug' => 'hidden', 'name' => 'Hidden', 'status' => 'draft']);
        $this->getJson('/api/v1/journeys?from=town-a&to=town-a')->assertStatus(422);
        $this->getJson('/api/v1/journeys?from=town-a&to=hidden')->assertNotFound();
        $this->getJson('/api/v1/journeys?from=town-a&to=x&within_km=500')->assertStatus(422);
        $this->getJson('/api/v1/journeys?from=Town%20A&to=hidden')->assertStatus(422);
        Destination::create(['slug' => 'town-b', 'name' => 'Town B', 'status' => 'published']);
        $this->getJson('/api/v1/journeys?from=town-a&to=town-b')->assertOk()->assertJsonCount(0, 'data');
    }
}

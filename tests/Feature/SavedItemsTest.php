<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\ServicePoint;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedItemsTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function services(): array
    {
        $d = Destination::create(['slug' => 'test-town', 'name' => 'Test town', 'status' => 'published']);
        $route = RideRoute::create(['slug' => 'test-road', 'name' => 'Test road', 'status' => 'published']);
        $seg = $route->segments()->create(['position' => 1, 'start_name' => 'Point A', 'end_name' => 'Point B', 'surface' => 'mixed']);
        $fuel = ServicePoint::create(['kind' => 'fuel', 'name' => 'Test pump', 'route_segment_id' => $seg->id, 'latitude' => 27.7, 'longitude' => 85.3, 'status' => 'published']);
        $mech = ServicePoint::create(['kind' => 'mechanic', 'name' => 'Test workshop', 'destination_id' => $d->id, 'status' => 'published']);
        $draft = ServicePoint::create(['kind' => 'medical', 'name' => 'Draft clinic', 'destination_id' => $d->id, 'status' => 'draft']);

        return [$fuel, $mech, $draft, $d];
    }

    public function test_riders_save_filter_and_remove_services(): void
    {
        [$fuel, $mech, $draft, $d] = $this->services();
        $rider = $this->rider();
        $this->as(null)->postJson('/api/v1/users/me/saved', ['type' => 'service', 'id' => $fuel->id])->assertUnauthorized();
        $this->as($rider)->postJson('/api/v1/users/me/saved', ['type' => 'service', 'id' => $draft->id])->assertNotFound();
        $this->as($rider)->postJson('/api/v1/users/me/saved', ['type' => 'plane', 'id' => $fuel->id])->assertStatus(422);
        foreach ([['service', $fuel->id], ['service', $mech->id], ['service', $mech->id], ['destination', $d->id]] as [$t, $id]) {
            $this->as($rider)->postJson('/api/v1/users/me/saved', ['type' => $t, 'id' => $id])->assertCreated();
        }
        $this->assertDatabaseCount('saved_items', 3); // saving twice keeps one row

        $this->as($rider)->getJson('/api/v1/users/me/saved?type=service')->assertOk()->assertJsonCount(2, 'data');
        $this->as($rider)->getJson('/api/v1/users/me/saved?type=service&kind=fuel')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Test pump')->assertJsonPath('data.0.place', 'Point A to Point B, Test road')
            ->assertJsonPath('data.0.link', '/routes/test-road')->assertJsonPath('data.0.latitude', 27.7);

        // Another rider sees none of it and cannot remove it.
        $other = $this->rider();
        $this->as($other)->getJson('/api/v1/users/me/saved')->assertJsonCount(0, 'data');
        $this->as($other)->deleteJson("/api/v1/users/me/saved/service/{$fuel->id}")->assertNoContent();
        $this->assertDatabaseCount('saved_items', 3);

        $this->as($rider)->deleteJson("/api/v1/users/me/saved/service/{$fuel->id}")->assertNoContent();
        $this->as($rider)->getJson('/api/v1/users/me/saved')->assertJsonCount(2, 'data');

        // An item that is no longer public drops out of the list.
        $mech->update(['status' => 'archived']);
        $this->as($rider)->getJson('/api/v1/users/me/saved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'destination');
    }

    public function test_a_saved_service_becomes_a_trip_stop(): void
    {
        [$fuel, , $draft] = $this->services();
        $rider = $this->rider();
        $trip = $this->as($rider)->postJson('/api/v1/trips', ['title' => 'Test trip'])->assertCreated()->json('data.id');
        $this->as($rider)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Test pump', 'service_point_id' => $fuel->id])->assertOk()
            ->assertJsonPath('data.stops.0.service_point_id', $fuel->id);
        $this->as($rider)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Test pump again', 'service_point_id' => $fuel->id])->assertStatus(422)->assertJsonValidationErrors('service_point_id');
        $this->as($rider)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Draft', 'service_point_id' => $draft->id])->assertStatus(422);
        $this->as($this->rider())->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Test pump', 'service_point_id' => $fuel->id])->assertForbidden();
        $this->assertSame(1, Trip::find($trip)->stops()->count());
    }
}

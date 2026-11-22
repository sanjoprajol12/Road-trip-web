<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Place;
use App\Models\RideRoute;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Session 17: places as trip stops (members' roles apply) and real route geometry imported by staff. */
class TripPlacesAndPathsTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();
        if (! $u) {
            $this->flushSession();
        }

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function place(string $slug, string $status = 'published'): Place
    {
        $p = Place::create(['slug' => $slug, 'name' => "Test {$slug}", 'category' => 'lake', 'description' => 'A test place.', 'latitude' => 28.0, 'longitude' => 84.0]);
        $p->forceFill(['status' => $status])->save();

        return $p;
    }

    public function test_places_in_private_and_group_trips_follow_member_roles(): void
    {
        $lake = $this->place('lake');
        $draft = $this->place('draft-lake', 'draft');
        $owner = $this->rider();
        $editor = $this->rider(['username' => 'editor_two']);
        $viewer = $this->rider(['username' => 'viewer_two']);
        $trip = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Lakes trip'])->json('data.id');

        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Test lake', 'place_id' => $lake->id])->assertOk()
            ->assertJsonPath('data.stops.0.place.slug', 'lake')->assertJsonPath('data.stops.0.place.position', [28, 84]);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Again', 'place_id' => $lake->id])->assertStatus(422)->assertJsonValidationErrors('place_id');
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Draft', 'place_id' => $draft->id])->assertStatus(422)->assertJsonValidationErrors('place_id');

        // Group roles: an editor may add and remove, a viewer may not, an outsider sees nothing.
        foreach ([['editor_two', 'editor', $editor], ['viewer_two', 'viewer', $viewer]] as [$username, $role, $who]) {
            $this->as($owner)->postJson("/api/v1/trips/{$trip}/members", ['username' => $username, 'role' => $role])->assertOk();
            $inv = $this->as($who)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
            $this->as($who)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertOk();
        }
        $other = $this->place('other-lake');
        $stop = $this->as($editor)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Other lake', 'place_id' => $other->id])->assertOk()->json('data.stops.1.id');
        $this->as($viewer)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Viewer try', 'place_id' => $this->place('third')->id])->assertForbidden();
        $this->as($viewer)->deleteJson("/api/v1/trips/{$trip}/stops/{$stop}")->assertForbidden();
        $this->as($viewer)->getJson("/api/v1/trips/{$trip}")->assertOk()->assertJsonCount(2, 'data.stops');
        $this->as($this->rider())->getJson("/api/v1/trips/{$trip}")->assertForbidden();
        $this->as(null)->getJson("/api/v1/trips/{$trip}")->assertUnauthorized();
        $this->as($editor)->deleteJson("/api/v1/trips/{$trip}/stops/{$stop}")->assertOk()->assertJsonCount(1, 'data.stops');

        // If the place is unpublished later, members keep the stop name but no place details.
        $lake->forceFill(['status' => 'archived'])->save();
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.place', null)->assertJsonPath('data.stops.0.linked_unavailable', true)->assertJsonPath('data.stops.0.name', 'Test lake');

        // The database refuses a second copy even if the API check is bypassed.
        $this->expectException(QueryException::class);
        $tripId = $trip;
        DB::table('trip_stops')->insert([['id' => (string) \Illuminate\Support\Str::uuid(), 'trip_id' => $tripId, 'position' => 9, 'name' => 'x', 'place_id' => $lake->id, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_staff_import_real_route_geometry_and_maps_draw_only_that(): void
    {
        $town = Destination::create(['slug' => 'test-town', 'name' => 'Test town', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 84.0]);
        $tracked = RideRoute::create(['slug' => 'tracked', 'name' => 'Tracked road', 'status' => 'published']);
        $seg = $tracked->segments()->create(['position' => 1, 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved', 'start_lat' => 28.05, 'start_lng' => 83.9, 'end_lat' => 28.05, 'end_lng' => 84.1]);
        $plain = RideRoute::create(['slug' => 'plain', 'name' => 'Endpoints only', 'status' => 'published']);
        $plain->segments()->create(['position' => 1, 'start_name' => 'C', 'end_name' => 'D', 'surface' => 'paved', 'start_lat' => 27.95, 'start_lng' => 83.9, 'end_lat' => 27.95, 'end_lng' => 84.1]);

        $gpx = '<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="28.05" lon="83.9"/><trkpt lat="28.07" lon="84.0"/><trkpt lat="28.05" lon="84.1"/></trkseg></trk></gpx>';
        $file = UploadedFile::fake()->createWithContent('track.gpx', $gpx);
        $this->as($this->rider())->postJson("/api/v1/admin/route-segments/{$seg->id}/path", ['track' => $file])->assertUnauthorized();
        $this->as($this->staff('admin'))->post("/api/v1/admin/route-segments/{$seg->id}/path", ['track' => UploadedFile::fake()->createWithContent('bad.gpx', str_replace('lat="28.07"', 'lat="35"', $gpx))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('track');
        $this->as($this->staff('admin'))->post("/api/v1/admin/route-segments/{$seg->id}/path", ['track' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.segments.0.path.1', [28.07, 84]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'route.path_imported']);

        $this->as(null)->getJson('/api/v1/routes/tracked')->assertJsonPath('data.segments.0.path.0', [28.05, 83.9]);
        $this->as(null)->getJson('/api/v1/routes/plain')->assertJsonPath('data.segments.0.path', null);
        $near = collect($this->as(null)->getJson('/api/v1/destinations/test-town')->json('nearby.routes'))->keyBy('slug');
        $this->assertCount(3, $near['tracked']['paths'][0]);
        $this->assertSame([], $near['plain']['paths']); // listed, never drawn
        $this->as($this->staff('admin'))->deleteJson("/api/v1/admin/route-segments/{$seg->id}/path")->assertOk()->assertJsonPath('data.segments.0.path', null);
    }
}

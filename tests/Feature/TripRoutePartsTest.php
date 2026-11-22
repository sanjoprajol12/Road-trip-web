<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Session 19 (F3): a whole route, single stretches and checkpoints as trip stops; stop order. */
class TripRoutePartsTest extends TestCase
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

    /** A -> B -> C -> D: the middle stretch has no distance, time or track. */
    private function route(string $slug = 'abcd', string $status = 'published'): RideRoute
    {
        $r = RideRoute::create(['slug' => $slug, 'name' => "Route {$slug}", 'status' => $status, 'distance_km' => 100, 'estimated_hours' => 5]);
        $r->segments()->create(['position' => 1, 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved', 'distance_km' => 40, 'estimated_hours' => 2, 'start_lat' => 28.1, 'start_lng' => 84.1, 'end_lat' => 28.2, 'end_lng' => 84.2]);
        $r->segments()->create(['position' => 2, 'start_name' => 'B', 'end_name' => 'C', 'surface' => 'gravel']);
        $last = $r->segments()->create(['position' => 3, 'start_name' => 'C', 'end_name' => 'D', 'surface' => 'dirt', 'distance_km' => 25, 'estimated_hours' => 1.5, 'end_lat' => 28.4, 'end_lng' => 84.4]);
        $last->forceFill(['path' => [[28.3, 84.3], [28.4, 84.4]]])->save();

        return $r->load('segments');
    }

    private function trip(User $owner): string
    {
        return $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Route trip'])->json('data.id');
    }

    private function join(string $trip, User $owner, User $who, string $role): void
    {
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/members", ['username' => $who->username, 'role' => $role])->assertOk();
        $inv = $this->as($who)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
        $this->as($who)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertOk();
    }

    public function test_whole_route_is_one_stop_with_recorded_totals_and_what_is_missing(): void
    {
        $r = $this->route();
        $owner = $this->rider();
        $trip = $this->trip($owner);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Home', 'destination_id' => Destination::create(['slug' => 'home', 'name' => 'Home', 'status' => 'published'])->id])->assertOk();

        $res = $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'whole' => true])->assertOk();
        $res->assertJsonCount(2, 'data.stops')->assertJsonPath('data.stops.1.route_part', 'route')->assertJsonPath('data.stops.1.name', 'Route abcd')->assertJsonPath('data.stops.1.position', 2)
            ->assertJsonPath('data.stops.1.route', ['slug' => 'abcd', 'name' => 'Route abcd', 'distance_km' => 100, 'estimated_hours' => 5, 'stretches' => 3, 'without_distance' => 1, 'without_time' => 1, 'without_track' => 2])
            ->assertJsonPath('data.stops.1.linked_unavailable', false)
            ->assertJsonPath('data.stops.0.destination.slug', 'home'); // existing destination stops are unchanged

        // Twice: refused by the API (both endpoints), and by the database if the API is bypassed.
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'whole' => true])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'Already on this trip: Route abcd.']);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Again', 'route_id' => $r->id])->assertStatus(422)->assertJsonValidationErrors('route_id');
        // Its stretches would count the same road twice; checkpoints are still fine.
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => [$r->segments[0]->id]])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'The whole route is already on this trip']);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'checkpoints' => [['segment_id' => $r->segments[0]->id, 'at' => 'end']]])->assertOk()
            ->assertJsonPath('data.stops.2.route_part', 'checkpoint_start')->assertJsonPath('data.stops.2.checkpoint', ['name' => 'B', 'position' => null]);

        $this->expectException(QueryException::class);
        DB::table('trip_stops')->insert(['id' => (string) Str::uuid(), 'trip_id' => $trip, 'position' => 9, 'name' => 'x', 'route_id' => $r->id, 'route_part' => 'route', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_stretches_and_checkpoints_keep_riding_order_in_either_direction_and_never_repeat(): void
    {
        $r = $this->route();
        [$ab, $bc, $cd] = $r->segments->all();
        $owner = $this->rider();
        $trip = $this->trip($owner);

        // Checkpoint B chosen twice (end of A-B and start of B-C) is one stop; order is A, A-B, B, C-D, D whatever the request order.
        $stops = $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => [$cd->id, $ab->id, $ab->id],
            'checkpoints' => [['segment_id' => $cd->id, 'at' => 'end'], ['segment_id' => $ab->id, 'at' => 'end'], ['segment_id' => $bc->id, 'at' => 'start'], ['segment_id' => $ab->id, 'at' => 'start']]])
            ->assertOk()->json('data.stops');
        $this->assertSame(['A', 'A to B', 'B', 'C to D', 'D'], array_column($stops, 'name'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($stops, 'position'));
        $this->assertSame(['checkpoint_start', 'stretch', 'checkpoint_start', 'stretch', 'checkpoint_end'], array_column($stops, 'route_part'));
        $this->assertSame(['from' => 'A', 'to' => 'B', 'distance_km' => 40, 'estimated_hours' => 2, 'surface' => 'paved', 'has_track' => false], $stops[1]['stretch']);
        $this->assertTrue($stops[3]['stretch']['has_track']);
        $this->assertSame(['name' => 'D', 'position' => [28.4, 84.4]], $stops[4]['checkpoint']);

        // Again: nothing is half-added; the names of the repeats are given.
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => [$bc->id, $ab->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'Already on this trip: A to B.']);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'whole' => true])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'Some stretches of this route']);
        $this->assertSame(5, DB::table('trip_stops')->where('trip_id', $trip)->count());

        // The missing stretch, ridden in reverse: named end to start; recorded values unchanged (no distance, no time).
        $stops = $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'reversed' => true, 'day' => 2, 'segment_ids' => [$bc->id]])->assertOk()->json('data.stops');
        $this->assertEquals(['name' => 'C to B', 'position' => 6, 'day' => 2], array_intersect_key($stops[5], array_flip(['name', 'position', 'day'])));
        $this->assertNull($stops[5]['stretch']['distance_km']);
        $this->assertNull($stops[5]['stretch']['estimated_hours']);

        // A fresh trip, reversed: D, C-D, C, B-C, B.
        $other = $this->trip($owner);
        $stops = $this->as($owner)->postJson("/api/v1/trips/{$other}/route-parts", ['route_id' => $r->id, 'reversed' => true, 'segment_ids' => [$bc->id, $cd->id], 'checkpoints' => [['segment_id' => $bc->id, 'at' => 'start'], ['segment_id' => $bc->id, 'at' => 'end'], ['segment_id' => $cd->id, 'at' => 'end']]])->assertOk()->json('data.stops');
        $this->assertSame(['D', 'D to C', 'C', 'C to B', 'B'], array_column($stops, 'name'));

        // The database refuses a second copy of a stretch.
        $this->expectException(QueryException::class);
        DB::table('trip_stops')->insert(['id' => (string) Str::uuid(), 'trip_id' => $trip, 'position' => 9, 'name' => 'x', 'route_id' => $r->id, 'route_segment_id' => $ab->id, 'route_part' => 'stretch', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_only_published_routes_and_their_own_stretches_are_accepted(): void
    {
        $r = $this->route();
        $draft = $this->route('draft', 'draft');
        $archived = $this->route('old', 'archived');
        $owner = $this->rider();
        $trip = $this->trip($owner);
        $url = "/api/v1/trips/{$trip}/route-parts";

        foreach ([$draft, $archived] as $x) {
            $this->as($owner)->postJson($url, ['route_id' => $x->id, 'whole' => true])->assertStatus(422)->assertJsonValidationErrors(['route_id' => 'not published']);
            $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Draft', 'route_id' => $x->id])->assertStatus(422)->assertJsonValidationErrors('route_id');
        }
        // A stretch or checkpoint of another route, under a published route id.
        $this->as($owner)->postJson($url, ['route_id' => $r->id, 'segment_ids' => [$draft->segments[0]->id]])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'not part of this route']);
        $this->as($owner)->postJson($url, ['route_id' => $r->id, 'checkpoints' => [['segment_id' => $draft->segments[0]->id, 'at' => 'start']]])->assertStatus(422)->assertJsonValidationErrors('checkpoints');
        // Nothing chosen, both chosen, malformed.
        $this->as($owner)->postJson($url, ['route_id' => $r->id])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'Choose the whole route']);
        $this->as($owner)->postJson($url, ['route_id' => $r->id, 'whole' => true, 'segment_ids' => [$r->segments[0]->id]])->assertStatus(422)->assertJsonValidationErrors(['segment_ids' => 'not both']);
        $this->as($owner)->postJson($url, ['route_id' => $r->id, 'checkpoints' => [['segment_id' => $r->segments[0]->id, 'at' => 'middle']]])->assertStatus(422)->assertJsonValidationErrors('checkpoints.0.at');
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonCount(0, 'data.stops');

        // Published when added, unpublished later: the stop name stays, route details go.
        $this->as($owner)->postJson($url, ['route_id' => $r->id, 'segment_ids' => [$r->segments[0]->id]])->assertOk();
        $r->update(['status' => 'archived']);
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.name', 'A to B')
            ->assertJsonPath('data.stops.0.route', null)->assertJsonPath('data.stops.0.stretch', null)->assertJsonPath('data.stops.0.linked_unavailable', true);
    }

    public function test_roles_decide_who_adds_reorders_and_removes(): void
    {
        $r = $this->route();
        $owner = $this->rider();
        $editor = $this->rider(['username' => 'route_editor']);
        $viewer = $this->rider(['username' => 'route_viewer']);
        $trip = $this->trip($owner);
        $this->join($trip, $owner, $editor, 'editor');
        $this->join($trip, $owner, $viewer, 'viewer');
        $url = "/api/v1/trips/{$trip}/route-parts";
        $body = ['route_id' => $r->id, 'segment_ids' => [$r->segments[0]->id]];

        $this->as(null)->postJson($url, $body)->assertUnauthorized();
        $this->as($this->rider())->postJson($url, $body)->assertForbidden();
        $this->as($viewer)->postJson($url, $body)->assertForbidden();
        $stops = $this->as($editor)->postJson($url, $body)->assertOk()->json('data.stops');
        $this->as($editor)->postJson($url, ['route_id' => $r->id, 'segment_ids' => [$r->segments[2]->id]])->assertOk();
        $ids = array_column($this->as($viewer)->getJson("/api/v1/trips/{$trip}")->assertOk()->json('data.stops'), 'id');

        $order = "/api/v1/trips/{$trip}/stops/order";
        $this->as(null)->putJson($order, ['ids' => array_reverse($ids)])->assertUnauthorized();
        $this->as($this->rider())->putJson($order, ['ids' => array_reverse($ids)])->assertForbidden();
        $this->as($viewer)->putJson($order, ['ids' => array_reverse($ids)])->assertForbidden();
        $this->as($viewer)->deleteJson("/api/v1/trips/{$trip}/stops/{$stops[0]['id']}")->assertForbidden();
        $this->as($viewer)->patchJson("/api/v1/trips/{$trip}/stops/{$stops[0]['id']}", ['note' => 'mine'])->assertForbidden();

        $after = $this->as($editor)->putJson($order, ['ids' => array_reverse($ids)])->assertOk()->json('data.stops');
        $this->assertSame(['C to D', 'A to B'], array_column($after, 'name'));
        $this->assertSame([1, 2], array_column($after, 'position'));
        $this->as($owner)->deleteJson("/api/v1/trips/{$trip}/stops/{$stops[0]['id']}")->assertOk()->assertJsonCount(1, 'data.stops');
    }

    public function test_reordering_needs_the_current_full_list_of_this_trip(): void
    {
        $r = $this->route();
        $owner = $this->rider();
        $trip = $this->trip($owner);
        $other = $this->trip($owner);
        $ids = array_column($this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => $r->segments->pluck('id')->all()])->json('data.stops'), 'id');
        $foreign = $this->as($owner)->postJson("/api/v1/trips/{$other}/stops", ['name' => 'Elsewhere'])->json('data.stops.0.id');
        $order = "/api/v1/trips/{$trip}/stops/order";

        $this->as($owner)->putJson($order, ['ids' => [$ids[0], $ids[1]]])->assertStatus(422)->assertJsonValidationErrors(['ids' => 'Reload the trip']);
        $this->as($owner)->putJson($order, ['ids' => [$ids[0], $ids[1], $foreign]])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->as($owner)->putJson($order, ['ids' => [$ids[0], $ids[0], $ids[1]]])->assertStatus(422)->assertJsonValidationErrors('ids.1');
        $this->as($owner)->putJson($order, ['ids' => [$ids[2], $ids[0], $ids[1]]])->assertOk();
        $this->assertSame(['C to D', 'A to B', 'B to C'], array_column($this->as($owner)->getJson("/api/v1/trips/{$trip}")->json('data.stops'), 'name'));
        $this->assertSame(1, DB::table('trip_stops')->where('id', $foreign)->value('position'));
    }

    public function test_route_stops_keep_their_links_and_survive_staff_edits(): void
    {
        $r = $this->route();
        $owner = $this->rider();
        $trip = $this->trip($owner);
        $stop = $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => [$r->segments[1]->id]])->json('data.stops.0.id');

        $this->as($owner)->patchJson("/api/v1/trips/{$trip}/stops/{$stop}", ['route_id' => $this->route('other')->id])->assertStatus(422)->assertJsonValidationErrors('route_id');
        $this->as($owner)->patchJson("/api/v1/trips/{$trip}/stops/{$stop}", ['day' => 3, 'overnight' => true, 'note' => 'Slow, gravel'])->assertOk()
            ->assertJsonPath('data.stops.0.day', 3)->assertJsonPath('data.stops.0.note', 'Slow, gravel')->assertJsonPath('data.stops.0.route_part', 'stretch');

        // Staff remove that stretch from the route: the trip keeps the stop by name and says the stretch is gone.
        $r->segments[1]->delete();
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.name', 'B to C')->assertJsonPath('data.stops.0.route_segment_id', null)
            ->assertJsonPath('data.stops.0.stretch', null)->assertJsonPath('data.stops.0.linked_unavailable', true)->assertJsonPath('data.stops.0.route.slug', 'abcd');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Place;
use App\Models\RideRoute;
use App\Models\StaffAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Places of interest (R2, R3, R9, R12, R13, A4, L4): staff management, rider suggestions, moderation, public reads. */
class PlacesTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();
        if (! $u) {
            $this->flushSession(); // a guest is a fresh browser: no staff or rider session left over from earlier requests
        }

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function staffWith(array $keys, ?\App\Models\User $linked = null): StaffAccount
    {
        $slug = 'r'.Str::lower(Str::random(8));
        DB::table('roles')->insert(['slug' => $slug, 'name' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $k) {
            DB::table('permission_role')->insert(['role_slug' => $slug, 'permission_key' => $k]);
        }

        return $this->staff($slug, $linked);
    }

    private function body(array $over = []): array
    {
        return $over + ['name' => 'Test viewpoint', 'category' => 'viewpoint', 'description' => 'A test place used by the automated test suite only.', 'latitude' => 28.0, 'longitude' => 84.0];
    }

    public function test_staff_create_edit_publish_and_the_public_sees_only_published(): void
    {
        $editor = $this->staffWith(['places.manage']);
        $this->as($editor)->postJson('/api/v1/admin/places', $this->body(['latitude' => 35.0]))->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->as($editor)->postJson('/api/v1/admin/places', ['name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['name', 'category', 'latitude', 'longitude']);
        $id = $this->as($editor)->postJson('/api/v1/admin/places', $this->body(['sources' => [['title' => 'Survey sheet', 'url' => 'https://example.org/s']]]))
            ->assertCreated()->assertJsonPath('data.slug', 'test-viewpoint')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.position', [28, 84])->json('data.id');
        $this->as(null)->getJson('/api/v1/places/test-viewpoint')->assertNotFound();
        $this->as(null)->getJson('/api/v1/places')->assertJsonCount(0, 'data');

        $this->as($editor)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'publish'])->assertForbidden(); // needs places.publish
        $publisher = $this->staffWith(['places.manage', 'places.publish']);
        $this->as($publisher)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'publish'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('audit_logs', ['action' => 'place.publish', 'subject_id' => $id]);
        $this->as($editor)->putJson("/api/v1/admin/places/{$id}", $this->body(['name' => 'Renamed']))->assertForbidden(); // live content
        $this->as($publisher)->putJson("/api/v1/admin/places/{$id}", $this->body(['latitude' => 28.01]))->assertOk();

        $this->as(null)->getJson('/api/v1/places/test-viewpoint')->assertOk()->assertJsonPath('data.position', [28.01, 84])
            ->assertJsonPath('data.sources.0.title', 'Survey sheet')->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.moderation_note');
        $this->as(null)->getJson('/api/v1/places?category=viewpoint&q=view')->assertJsonCount(1, 'data');
        $this->as(null)->getJson('/api/v1/places?category=lake')->assertJsonCount(0, 'data');
        $this->as($publisher)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'unpublish'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->as(null)->getJson('/api/v1/places/test-viewpoint')->assertNotFound();
        $this->as($this->rider())->getJson('/api/v1/admin/places')->assertUnauthorized();
    }

    public function test_rider_suggestion_moderation_and_no_self_review(): void
    {
        $rider = $this->rider();
        $this->as(null)->postJson('/api/v1/places', $this->body())->assertUnauthorized();
        $this->as($rider)->postJson('/api/v1/places', $this->body(['description' => 'short']))->assertStatus(422);
        $id = $this->as($rider)->postJson('/api/v1/places', $this->body(['status' => 'published']))->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')->json('data.id'); // riders cannot publish
        $this->as($rider)->getJson('/api/v1/users/me/places')->assertJsonPath('data.0.status', 'pending_review');
        $this->as($this->rider())->getJson('/api/v1/users/me/places')->assertJsonCount(0, 'data');
        $this->as($this->rider())->patchJson("/api/v1/places/{$id}", $this->body())->assertNotFound(); // someone else's
        $this->as(null)->getJson('/api/v1/places/test-viewpoint')->assertNotFound();
        $this->as($rider)->getJson('/api/v1/places/test-viewpoint')->assertOk()->assertJsonPath('data.status', 'pending_review'); // own

        // Duplicate: same category within 150 m is refused.
        $this->as($this->rider())->postJson('/api/v1/places', $this->body(['name' => 'Another name', 'latitude' => 28.0005]))->assertStatus(422)->assertJsonValidationErrors('name');

        // The rider's linked staff account cannot review it; another staff member can.
        $self = $this->staffWith(['places.manage', 'places.publish'], $rider);
        $this->as($self)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'publish'])->assertStatus(422);
        $mod = $this->staffWith(['places.manage', 'places.publish']);
        $this->as($mod)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'request_changes', 'note' => 'short'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->as($mod)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'request_changes', 'note' => 'Please add how to reach it from the road.'])->assertOk();
        $this->assertSame(1, $rider->notifications()->count());
        $this->as($rider)->getJson('/api/v1/users/me/places')->assertJsonPath('data.0.moderation_note', 'Please add how to reach it from the road.');

        $this->as($rider)->patchJson("/api/v1/places/{$id}", $this->body(['description' => 'Walk ten minutes up the marked path from the road bend.']))->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->as($mod)->postJson("/api/v1/admin/places/{$id}/decision", ['decision' => 'publish'])->assertOk();
        $this->as($rider)->patchJson("/api/v1/places/{$id}", $this->body())->assertForbidden(); // reviewed: corrections only
        $history = collect($this->as($mod)->getJson("/api/v1/admin/places/{$id}/history")->assertOk()->json('data'))->pluck('action')->all();
        $this->assertSame(['place.suggested', 'place.request_changes', 'place.resubmitted', 'place.publish'], $history);
        $this->as($rider)->getJson("/api/v1/admin/places/{$id}/history")->assertUnauthorized();

        // Reject needs a reason and keeps the record.
        $other = $this->as($this->rider())->postJson('/api/v1/places', $this->body(['name' => 'Far lake', 'category' => 'lake', 'latitude' => 29.0]))->json('data.id');
        $this->as($mod)->postJson("/api/v1/admin/places/{$other}/decision", ['decision' => 'reject', 'note' => 'This is a private garden, not public.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('places', ['id' => $other, 'status' => 'rejected']);
    }

    public function test_corrections_are_reviewed_never_applied_automatically(): void
    {
        $place = Place::create($this->body() + ['slug' => 'test-viewpoint']);
        $place->forceFill(['status' => 'published'])->save();
        $rider = $this->rider();
        $this->as($rider)->postJson("/api/v1/places/{$place->id}/corrections", ['body' => 'The viewpoint moved after the landslide.', 'latitude' => 28.01])->assertStatus(422);
        $this->as($rider)->postJson("/api/v1/places/{$place->id}/corrections", ['body' => 'The viewpoint moved after the landslide.', 'latitude' => 28.01, 'longitude' => 84.0])->assertCreated();
        $this->as($rider)->postJson("/api/v1/places/{$place->id}/corrections", ['body' => 'Second correction while one is pending.'])->assertStatus(422);
        $this->assertSame(28.0, $place->refresh()->latitude); // nothing changed by itself

        // Correction review is places.review since the session 17 follow-up; manage or publish alone are refused.
        $mod = $this->staffWith(['places.review']);
        $cid = $this->as($mod)->getJson('/api/v1/admin/place-corrections')->assertJsonCount(1, 'data')->json('data.0.id');
        $this->as($this->staffWith(['places.manage']))->postJson("/api/v1/admin/place-corrections/{$cid}/decision", ['decision' => 'accepted', 'note' => 'Moved.'])->assertForbidden();
        $this->as($this->staffWith(['places.manage', 'places.publish']))->getJson('/api/v1/admin/place-corrections')->assertForbidden();
        $this->as($mod)->postJson("/api/v1/admin/place-corrections/{$cid}/decision", ['decision' => 'accepted', 'note' => 'Checked against photos.'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'place.correction_accepted', 'subject_id' => $place->id]);
        $this->as($mod)->postJson("/api/v1/admin/place-corrections/{$cid}/decision", ['decision' => 'rejected', 'note' => 'Again.'])->assertStatus(422);
    }

    public function test_places_appear_near_destinations_along_journeys_in_saved_items_and_experiences(): void
    {
        $here = Destination::create(['slug' => 'test-town', 'name' => 'Test town', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 84.0]);
        $end = Destination::create(['slug' => 'test-end', 'name' => 'Test end', 'status' => 'published', 'latitude' => 28.0, 'longitude' => 85.0]);
        $near = Place::create($this->body(['name' => 'Near lake', 'category' => 'lake', 'latitude' => 28.05]) + ['slug' => 'near-lake']);
        $near->forceFill(['status' => 'published'])->save();
        Place::create($this->body(['name' => 'Draft temple', 'category' => 'temple', 'latitude' => 28.02]) + ['slug' => 'draft-temple']);
        $r = RideRoute::create(['slug' => 'test-road', 'name' => 'Test road', 'status' => 'published', 'start_destination_id' => $here->id, 'end_destination_id' => $end->id]);
        $r->segments()->create(['position' => 1, 'start_name' => 'Test town', 'end_name' => 'Test end', 'surface' => 'paved', 'start_lat' => 28.0, 'start_lng' => 84.0, 'end_lat' => 28.0, 'end_lng' => 85.0]);

        $this->getJson('/api/v1/destinations/test-town')->assertJsonPath('nearby.places.0.slug', 'near-lake')->assertJsonCount(1, 'nearby.places');
        $journey = collect($this->getJson('/api/v1/journeys?from=test-town&to=test-end')->json('data.0.places'));
        $this->assertSame('/places/near-lake', $journey->firstWhere('type', 'place')['link']);
        $this->assertNull($journey->firstWhere('name', 'Draft temple'));
        $this->getJson('/api/v1/places/near-lake')->assertOk()->assertJsonPath('nearby.destinations.0.slug', 'test-town');

        $rider = $this->rider();
        $this->as($rider)->postJson('/api/v1/users/me/saved', ['type' => 'place', 'id' => $near->id])->assertCreated();
        $this->as($rider)->getJson('/api/v1/users/me/saved?type=place')->assertJsonPath('data.0.link', '/places/near-lake');
        $draftId = Place::where('slug', 'draft-temple')->value('id');
        $this->as($rider)->postJson('/api/v1/users/me/saved', ['type' => 'place', 'id' => $draftId])->assertNotFound();

        $cid = $this->as($rider)->postJson('/api/v1/contributions', ['kind' => 'observation', 'title' => 'Calm water in the morning', 'body' => 'The lake was calm and easy to reach on foot from the road.',
            'observed_on' => now()->subDay()->toDateString(), 'place_id' => $near->id])->assertCreated()->assertJsonPath('data.place.slug', 'near-lake')->json('data.id');
        DB::table('contributions')->where('id', $cid)->update(['status' => 'published']);
        $this->getJson('/api/v1/places/near-lake')->assertJsonPath('experiences.0.id', $cid);
        $this->as($rider)->postJson('/api/v1/contributions', ['kind' => 'observation', 'title' => 'Draft place report', 'body' => 'Filed under a place that is not published yet.',
            'observed_on' => now()->subDay()->toDateString(), 'place_id' => $draftId])->assertStatus(422);
    }
}

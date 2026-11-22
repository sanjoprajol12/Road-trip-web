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

/** Session 20 (F4, F15): stops and accommodation, planned and spent costs, checklists, notes, shared editing. */
class TripPlanningTest extends TestCase
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

    /** @return array{0: string, 1: User, 2: User, 3: User} trip id, owner, editor, viewer */
    private function group(): array
    {
        $owner = $this->rider();
        $editor = $this->rider(['username' => 'plan_editor']);
        $viewer = $this->rider(['username' => 'plan_viewer']);
        $trip = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Annapurna plan', 'starts_on' => '2027-03-01', 'ends_on' => '2027-03-03'])->json('data.id');
        foreach ([[$editor, 'editor'], [$viewer, 'viewer']] as [$who, $role]) {
            $this->as($owner)->postJson("/api/v1/trips/{$trip}/members", ['username' => $who->username, 'role' => $role])->assertOk();
            $inv = $this->as($who)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
            $this->as($who)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertOk();
        }

        return [$trip, $owner, $editor, $viewer];
    }

    public function test_accommodation_stops_are_added_moved_to_another_day_and_removed_by_editors_only(): void
    {
        [$trip, $owner, $editor, $viewer] = $this->group();
        $url = "/api/v1/trips/{$trip}/stops";
        $stop = $this->as($editor)->postJson($url, ['name' => 'Guest house in Tatopani', 'day' => 1, 'overnight' => true, 'note' => 'Not booked yet'])->assertOk()
            ->assertJsonPath('data.stops.0.overnight', true)->assertJsonPath('data.stops.0.day', 1)->assertJsonPath('data.stops.0.note', 'Not booked yet')->json('data.stops.0.id');

        // An editor's change is what every member reads next.
        $this->as($editor)->patchJson("{$url}/{$stop}", ['day' => 2, 'name' => 'Guest house in Ghasa', 'note' => null])->assertOk();
        $this->as($viewer)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.day', 2)->assertJsonPath('data.stops.0.name', 'Guest house in Ghasa')
            ->assertJsonPath('data.stops.0.note', null)->assertJsonPath('data.stops.0.overnight', true);
        $this->as($owner)->patchJson("{$url}/{$stop}", ['overnight' => false])->assertOk()->assertJsonPath('data.stops.0.overnight', false);

        // Viewers and outsiders change nothing; bad values are refused.
        $this->as($viewer)->patchJson("{$url}/{$stop}", ['day' => 3])->assertForbidden();
        $this->as($viewer)->deleteJson("{$url}/{$stop}")->assertForbidden();
        $this->as($this->rider())->patchJson("{$url}/{$stop}", ['day' => 3])->assertForbidden();
        $this->as(null)->patchJson("{$url}/{$stop}", ['day' => 3])->assertUnauthorized();
        $this->as($editor)->patchJson("{$url}/{$stop}", ['day' => 0, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['day', 'name']);
        $this->as($editor)->deleteJson("{$url}/{$stop}")->assertOk()->assertJsonCount(0, 'data.stops');
    }

    public function test_a_demoted_editor_can_no_longer_change_the_plan(): void
    {
        [$trip, $owner, $editor] = $this->group();
        $this->as($editor)->postJson("/api/v1/trips/{$trip}/checklist", ['label' => 'Spare tube'])->assertOk();
        $this->as($owner)->patchJson("/api/v1/trips/{$trip}/members/{$editor->id}", ['role' => 'viewer'])->assertOk();
        $this->as($editor)->postJson("/api/v1/trips/{$trip}/checklist", ['label' => 'Chain lube'])->assertForbidden();
        $this->as($editor)->patchJson("/api/v1/trips/{$trip}", ['notes' => 'mine'])->assertForbidden();
        $this->as($editor)->getJson("/api/v1/trips/{$trip}")->assertOk()->assertJsonPath('data.my_role', 'viewer')->assertJsonCount(1, 'data.checklist');
    }

    public function test_planned_and_spent_costs_are_kept_apart_and_unknown_amounts_are_counted_not_guessed(): void
    {
        [$trip, $owner, $editor, $viewer] = $this->group();
        $url = "/api/v1/trips/{$trip}/expenses";
        $this->as($editor)->postJson($url, ['label' => 'Fuel estimate', 'category' => 'fuel', 'amount_npr' => 6000])->assertOk()->assertJsonPath('data.expenses.0.status', 'planned');
        $this->as($editor)->postJson($url, ['label' => 'Permit (price unknown)', 'category' => 'permits'])->assertOk();
        $spent = $this->as($owner)->postJson($url, ['label' => 'Lodging Beni', 'category' => 'lodging', 'amount_npr' => 1500, 'status' => 'spent', 'paid_by' => $owner->id])->assertOk()
            ->assertJsonPath('data.budget', ['planned_npr' => 6000, 'spent_npr' => 1500, 'planned_without_amount' => 1])->json('data.expenses.2.id');

        // Spent needs an amount, on create and when a planned item is marked as spent.
        $this->as($editor)->postJson($url, ['label' => 'Food', 'status' => 'spent'])->assertStatus(422)->assertJsonValidationErrors(['amount_npr' => 'Enter the amount you spent.']);
        $unknown = DB::table('trip_expenses')->where('label', 'Permit (price unknown)')->value('id');
        $this->as($editor)->patchJson("{$url}/{$unknown}", ['status' => 'spent'])->assertStatus(422)->assertJsonValidationErrors('amount_npr');
        $this->as($editor)->patchJson("{$url}/{$spent}", ['amount_npr' => null])->assertStatus(422)->assertJsonValidationErrors('amount_npr');
        $this->as($editor)->patchJson("{$url}/{$unknown}", ['status' => 'spent', 'amount_npr' => 3000, 'paid_by' => $editor->id])->assertOk()
            ->assertJsonPath('data.budget', ['planned_npr' => 6000, 'spent_npr' => 4500, 'planned_without_amount' => 0]);
        $this->as($editor)->postJson($url, ['label' => 'x', 'status' => 'paid', 'amount_npr' => -1, 'category' => 'cash'])->assertStatus(422)->assertJsonValidationErrors(['status', 'amount_npr', 'category', 'label']);

        // Only editors and the owner change the budget.
        $this->as($viewer)->postJson($url, ['label' => 'Viewer try', 'amount_npr' => 1])->assertForbidden();
        $this->as($viewer)->patchJson("{$url}/{$spent}", ['amount_npr' => 1])->assertForbidden();
        $this->as($viewer)->deleteJson("{$url}/{$spent}")->assertForbidden();
        $this->as($this->rider())->deleteJson("{$url}/{$spent}")->assertForbidden();
        $this->as($viewer)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.budget.spent_npr', 4500)->assertJsonCount(3, 'data.expenses');

        // The database refuses a spent row without an amount even if the API is bypassed.
        $this->expectException(QueryException::class);
        DB::table('trip_expenses')->insert(['id' => (string) Str::uuid(), 'trip_id' => $trip, 'label' => 'x', 'status' => 'spent', 'amount_npr' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_checklist_items_are_edited_ticked_unticked_and_removed_by_editors_only(): void
    {
        [$trip, , $editor, $viewer] = $this->group();
        $url = "/api/v1/trips/{$trip}/checklist";
        $id = $this->as($editor)->postJson($url, ['label' => 'Chain lube', 'section' => 'motorcycle'])->assertOk()->assertJsonPath('data.checklist.0.done', false)->json('data.checklist.0.id');
        $this->as($editor)->patchJson("{$url}/{$id}", ['label' => 'Chain lube and rag', 'section' => 'personal'])->assertOk()
            ->assertJsonPath('data.checklist.0.label', 'Chain lube and rag')->assertJsonPath('data.checklist.0.section', 'personal');
        $this->as($editor)->patchJson("{$url}/{$id}", ['done' => true])->assertOk()->assertJsonPath('data.checklist.0.done', true);
        $this->as($editor)->patchJson("{$url}/{$id}", ['done' => false])->assertOk()->assertJsonPath('data.checklist.0.done', false);
        $this->as($editor)->patchJson("{$url}/{$id}", ['label' => 'x', 'section' => 'garage'])->assertStatus(422)->assertJsonValidationErrors(['label', 'section']);
        $this->as($viewer)->patchJson("{$url}/{$id}", ['done' => true])->assertForbidden();
        $this->as($viewer)->deleteJson("{$url}/{$id}")->assertForbidden();
        $this->as($editor)->deleteJson("{$url}/{$id}")->assertOk()->assertJsonCount(0, 'data.checklist');
    }

    public function test_trip_notes_are_written_changed_and_cleared_by_editors_and_read_only_by_members(): void
    {
        [$trip, $owner, $editor, $viewer] = $this->group();
        $url = "/api/v1/trips/{$trip}";
        $this->as($owner)->patchJson($url, ['notes' => 'Meet at the Lakeside pump at 7.'])->assertOk()->assertJsonPath('data.notes', 'Meet at the Lakeside pump at 7.');
        $this->as($editor)->patchJson($url, ['notes' => 'Meet at 6.30 instead.'])->assertOk();
        $this->as($viewer)->getJson($url)->assertJsonPath('data.notes', 'Meet at 6.30 instead.');
        $this->as($viewer)->patchJson($url, ['notes' => 'Viewer edit'])->assertForbidden();
        $this->as($this->rider())->getJson($url)->assertForbidden();
        $this->as($editor)->patchJson($url, ['notes' => str_repeat('a', 10001)])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->as($editor)->patchJson($url, ['notes' => null])->assertOk()->assertJsonPath('data.notes', null);
    }

    public function test_withdrawn_destinations_keep_the_stop_and_recorded_tracks_reach_the_trip_map(): void
    {
        [$trip, $owner] = $this->group();
        $town = Destination::create(['slug' => 'tatopani', 'name' => 'Tatopani', 'status' => 'published', 'latitude' => 28.48, 'longitude' => 83.65]);
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/stops", ['name' => 'Hot springs night', 'destination_id' => $town->id, 'overnight' => true])->assertOk()
            ->assertJsonPath('data.stops.0.destination.slug', 'tatopani')->assertJsonPath('data.stops.0.linked_unavailable', false);
        $town->update(['status' => 'archived']);
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.destination', null)->assertJsonPath('data.stops.0.linked_unavailable', true)->assertJsonPath('data.stops.0.name', 'Hot springs night');
        $town->delete(); // soft delete: the stop keeps its name and says the destination is gone
        $this->as($owner)->getJson("/api/v1/trips/{$trip}")->assertJsonPath('data.stops.0.destination', null)->assertJsonPath('data.stops.0.linked_unavailable', true)->assertJsonPath('data.stops.0.name', 'Hot springs night');

        $r = RideRoute::create(['slug' => 'kali', 'name' => 'Kali Gandaki', 'status' => 'published']);
        $tracked = $r->segments()->create(['position' => 1, 'start_name' => 'Beni', 'end_name' => 'Tatopani', 'surface' => 'mixed']);
        $tracked->forceFill(['path' => [[28.35, 83.56], [28.48, 83.65]]])->save();
        $plain = $r->segments()->create(['position' => 2, 'start_name' => 'Tatopani', 'end_name' => 'Ghasa', 'surface' => 'gravel']);
        $stops = $this->as($owner)->postJson("/api/v1/trips/{$trip}/route-parts", ['route_id' => $r->id, 'segment_ids' => [$tracked->id, $plain->id]])->assertOk()->json('data.stops');
        $this->assertSame([[[28.35, 83.56], [28.48, 83.65]]], $stops[1]['tracks']);
        $this->assertSame([], $stops[2]['tracks']); // no recorded track: no line, never a straight one
        $this->assertSame([], $stops[0]['tracks']);
        $other = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Whole'])->json('data.id');
        $this->as($owner)->postJson("/api/v1/trips/{$other}/route-parts", ['route_id' => $r->id, 'whole' => true])->assertOk()
            ->assertJsonPath('data.stops.0.tracks', [[[28.35, 83.56], [28.48, 83.65]]]);
    }
}

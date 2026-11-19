<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    public function test_trips_are_private_to_their_members(): void
    {
        $owner = $this->rider();
        $stranger = $this->rider();
        $id = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Mustang loop', 'starts_on' => '2027-04-01', 'ends_on' => '2027-04-10'])
            ->assertCreated()->assertJsonPath('data.my_role', 'owner')->json('data.id');
        $this->as($stranger)->getJson("/api/v1/trips/{$id}")->assertForbidden();
        $this->as($stranger)->patchJson("/api/v1/trips/{$id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->as($stranger)->postJson("/api/v1/trips/{$id}/stops", ['name' => 'Sneaky'])->assertForbidden();
        $this->as($stranger)->getJson('/api/v1/trips')->assertOk()->assertJsonCount(0, 'data');
        $this->as(null)->getJson("/api/v1/trips/{$id}")->assertUnauthorized();
        $this->as($this->staff('admin'))->getJson("/api/v1/trips/{$id}")->assertUnauthorized(); // staff have no rider session, so no way into private trips
    }

    public function test_group_roles_owner_editor_viewer(): void
    {
        $owner = $this->rider();
        $editor = $this->rider(['username' => 'editor_one']);
        $viewer = $this->rider(['username' => 'viewer_one']);
        $id = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Group ride plan'])->json('data.id');
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'editor_one', 'role' => 'editor'])->assertOk();
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'viewer_one', 'role' => 'viewer'])->assertOk();
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'viewer_one', 'role' => 'editor'])->assertStatus(422);
        // Nobody is a member until they accept.
        $this->as($editor)->getJson("/api/v1/trips/{$id}")->assertForbidden();
        foreach ([$editor, $viewer] as $who) {
            $inv = $this->as($who)->getJson('/api/v1/users/me/trip-invitations')->assertOk()->assertJsonPath('data.0.trip_title', 'Group ride plan')->json('data.0.id');
            $this->as($who)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertOk();
        }

        $this->as($editor)->postJson("/api/v1/trips/{$id}/stops", ['name' => 'Beni', 'day' => 1, 'overnight' => true])->assertOk()->assertJsonCount(1, 'data.stops');
        $this->as($editor)->postJson("/api/v1/trips/{$id}/expenses", ['label' => 'Fuel', 'category' => 'fuel', 'amount_npr' => 2500, 'paid_by' => $editor->id])->assertOk()->assertJsonPath('data.expense_total_npr', 2500);
        $this->as($viewer)->getJson("/api/v1/trips/{$id}")->assertOk()->assertJsonPath('data.my_role', 'viewer');
        $this->as($viewer)->postJson("/api/v1/trips/{$id}/checklist", ['label' => 'Chain lube'])->assertForbidden();
        $this->as($editor)->postJson("/api/v1/trips/{$id}/members", ['username' => 'viewer_one', 'role' => 'editor'])->assertForbidden();
        $this->as($editor)->deleteJson("/api/v1/trips/{$id}")->assertForbidden();
        $this->as($viewer)->deleteJson("/api/v1/trips/{$id}/members/{$viewer->id}")->assertNoContent(); // leave
        $this->as($viewer)->getJson("/api/v1/trips/{$id}")->assertForbidden();
        $this->as($owner)->deleteJson("/api/v1/trips/{$id}/members/{$owner->id}")->assertStatus(422);
        $this->as($editor)->postJson("/api/v1/trips/{$id}/expenses", ['label' => 'x', 'amount_npr' => 1, 'paid_by' => $viewer->id])->assertStatus(422);
    }

    public function test_items_of_another_trip_cannot_be_reached_through_my_trip(): void
    {
        $a = $this->rider();
        $b = $this->rider();
        $tripA = $this->as($a)->postJson('/api/v1/trips', ['title' => 'Trip A'])->json('data.id');
        $stopA = $this->as($a)->postJson("/api/v1/trips/{$tripA}/stops", ['name' => 'Stop A'])->json('data.stops.0.id');
        $tripB = $this->as($b)->postJson('/api/v1/trips', ['title' => 'Trip B'])->json('data.id');
        $this->as($b)->patchJson("/api/v1/trips/{$tripB}/stops/{$stopA}", ['name' => 'Renamed'])->assertNotFound();
        $this->as($b)->deleteJson("/api/v1/trips/{$tripB}/stops/{$stopA}")->assertNotFound();
        $this->as($a)->getJson("/api/v1/trips/{$tripA}")->assertJsonPath('data.stops.0.name', 'Stop A');
    }

    public function test_emergency_contacts_are_private(): void
    {
        $a = $this->rider();
        $id = $this->as($a)->postJson('/api/v1/users/me/emergency-contacts', ['name' => 'Sister', 'phone' => '+977 980000000', 'relation' => 'family'])->assertCreated()->json('data.id');
        $this->as($a)->postJson('/api/v1/users/me/emergency-contacts', ['name' => 'Bad', 'phone' => 'call me'])->assertStatus(422);
        $b = $this->rider();
        $this->as($b)->getJson('/api/v1/users/me/emergency-contacts')->assertJsonCount(0, 'data');
        $this->as($b)->patchJson("/api/v1/users/me/emergency-contacts/{$id}", ['name' => 'Mine now', 'phone' => '123456'])->assertNotFound();
        $this->as($b)->deleteJson("/api/v1/users/me/emergency-contacts/{$id}")->assertNotFound();
    }

    public function test_invitations_need_consent_expire_and_can_be_cancelled(): void
    {
        $owner = $this->rider();
        $guest = $this->rider(['username' => 'guest_one']);
        $other = $this->rider(['username' => 'other_one']);
        $id = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Private plan'])->json('data.id');
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'guest_one', 'role' => 'editor'])->assertOk()
            ->assertJsonPath('data.invitations.0.username', 'guest_one')->assertJsonCount(1, 'data.members');
        $this->assertSame(1, $guest->notifications()->count());
        $inv = $this->as($guest)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');

        // Only the invitee answers; someone else's invitation looks like none.
        $this->as($other)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertNotFound();
        $this->as($guest)->postJson("/api/v1/trip-invitations/{$inv}/decline")->assertOk();
        $this->as($guest)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertStatus(422);
        $this->as($guest)->getJson("/api/v1/trips/{$id}")->assertForbidden();

        // Re-invite, owner cancels: cannot be accepted.
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'guest_one', 'role' => 'viewer'])->assertOk();
        $inv2 = $this->as($guest)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
        $this->as($guest)->deleteJson("/api/v1/trips/{$id}/invitations/{$inv2}")->assertForbidden();
        $this->as($owner)->deleteJson("/api/v1/trips/{$id}/invitations/{$inv2}")->assertNoContent();
        $this->as($guest)->postJson("/api/v1/trip-invitations/{$inv2}/accept")->assertStatus(422);

        // Expired after 14 days: hidden, refused, and the owner may invite again.
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'guest_one', 'role' => 'viewer'])->assertOk();
        $inv3 = $this->as($guest)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
        \Illuminate\Support\Facades\DB::table('trip_invitations')->where('id', $inv3)->update(['expires_at' => now()->subMinute()]);
        $this->as($guest)->getJson('/api/v1/users/me/trip-invitations')->assertJsonCount(0, 'data');
        $this->as($guest)->postJson("/api/v1/trip-invitations/{$inv3}/accept")->assertStatus(422);
        $this->as($owner)->postJson("/api/v1/trips/{$id}/members", ['username' => 'guest_one', 'role' => 'viewer'])->assertOk();
        $inv4 = $this->as($guest)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
        $this->as($guest)->postJson("/api/v1/trip-invitations/{$inv4}/accept")->assertOk()->assertJsonPath('trip_id', $id);
        $this->as($guest)->getJson("/api/v1/trips/{$id}")->assertOk()->assertJsonPath('data.my_role', 'viewer')->assertJsonMissingPath('data.invitations');
    }
}

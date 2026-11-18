<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return $over + [
            'category' => 'ride', 'subtype' => 'weekend ride', 'title' => 'Dhulikhel weekend ride',
            'description' => 'A short test ride used by the automated test suite only.',
            'starts_at' => now()->addWeek()->toIso8601String(), 'ends_at' => now()->addWeek()->addHours(6)->toIso8601String(),
            'meeting_point' => 'Test meeting point', 'visibility' => 'public', 'join_method' => 'instant',
        ];
    }

    private function published(User $org, array $over = []): string
    {
        $id = $this->as($org)->postJson('/api/v1/events', $this->payload($over))->assertCreated()->json('data.id');
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'published'])->assertOk();

        return $id;
    }

    private function as(?User $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    public function test_only_verified_organizers_host_and_revocation_stops_them(): void
    {
        $this->as($this->rider())->postJson('/api/v1/events', $this->payload())->assertForbidden();
        $org = $this->organizer();
        $id = $this->published($org);
        $org->verifications()->update(['status' => 'revoked', 'review_note' => 'Revoked in a test case.']);
        $this->as($org)->postJson('/api/v1/events', $this->payload())->assertForbidden();
        $this->as($org)->patchJson("/api/v1/events/{$id}", ['title' => 'Still mine?'])->assertForbidden();
        $this->as($org)->getJson("/api/v1/events/{$id}/registrations")->assertForbidden();
    }

    public function test_drafts_and_private_events_are_hidden_from_outsiders(): void
    {
        $org = $this->organizer();
        $draft = $this->as($org)->postJson('/api/v1/events', $this->payload())->json('data.id');
        $private = $this->published($org, ['visibility' => 'private']);
        $outsider = $this->rider();
        foreach ([$draft, $private] as $id) {
            $this->as(null)->getJson("/api/v1/events/{$id}")->assertForbidden();
            $this->as($outsider)->getJson("/api/v1/events/{$id}")->assertForbidden();
        }
        $this->as(null)->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
        // Private events need an invitation even to join.
        $this->as($outsider)->postJson("/api/v1/events/{$private}/join")->assertForbidden();
        $invitee = $this->rider(['username' => 'invited_rider']);
        $this->as($org)->postJson("/api/v1/events/{$private}/invitations", ['username' => 'invited_rider'])->assertCreated();
        $this->as($invitee)->getJson("/api/v1/events/{$private}")->assertOk()->assertJsonPath('data.my_registration', 'invited');
        $this->as($invitee)->postJson("/api/v1/events/{$private}/join")->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_instant_join_respects_capacity_and_promotes_the_waitlist(): void
    {
        $org = $this->organizer();
        $id = $this->published($org, ['capacity' => 2, 'waitlist_enabled' => true]);
        [$a, $b, $c] = [$this->rider(), $this->rider(), $this->rider()];
        $this->as($a)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'approved');
        $this->as($b)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'approved')->assertJsonPath('event_status', 'full');
        $this->as($c)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'waitlisted');
        $this->as($a)->postJson("/api/v1/events/{$id}/join")->assertStatus(422);
        $this->as($a)->postJson("/api/v1/events/{$id}/leave")->assertOk();
        $this->as($c)->getJson("/api/v1/events/{$id}")->assertJsonPath('data.my_registration', 'approved');
        $this->assertSame('full', Event::find($id)->status->value);
        $this->as($org)->postJson("/api/v1/events/{$id}/join")->assertStatus(422);
    }

    public function test_full_event_without_waitlist_refuses(): void
    {
        $id = $this->published($this->organizer(), ['capacity' => 1]);
        $this->as($this->rider())->postJson("/api/v1/events/{$id}/join")->assertOk();
        $this->as($this->rider())->postJson("/api/v1/events/{$id}/join")->assertStatus(422)->assertJsonValidationErrors('event');
    }

    public function test_approval_join_and_organizer_decisions(): void
    {
        $org = $this->organizer();
        $id = $this->published($org, ['join_method' => 'approval']);
        $rider = $this->rider();
        $this->as($rider)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'pending');
        $this->as($rider)->getJson("/api/v1/events/{$id}/registrations")->assertForbidden();
        $reg = $this->as($org)->getJson("/api/v1/events/{$id}/registrations")->assertOk()->json('data.0.id');
        $this->as($rider)->postJson("/api/v1/events/{$id}/registrations/{$reg}", ['status' => 'approved'])->assertForbidden();
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$reg}", ['status' => 'attended'])->assertStatus(422);
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$reg}", ['status' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_cancellation_notifies_participants_and_closes_registration(): void
    {
        $org = $this->organizer();
        $id = $this->published($org);
        $rider = $this->rider();
        $this->as($rider)->postJson("/api/v1/events/{$id}/join")->assertOk();
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'cancelled', 'message' => 'Landslide on the access road this week.'])->assertOk();
        $this->as($rider)->getJson('/api/v1/users/me/notifications')->assertJsonPath('data.0.data.kind', 'event_cancellation');
        $this->as($this->rider())->postJson("/api/v1/events/{$id}/join")->assertStatus(422);
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'published'])->assertStatus(422);
    }

    public function test_staff_can_cancel_any_event_but_riders_cannot(): void
    {
        $id = $this->published($this->organizer());
        $this->as($this->rider())->postJson("/api/v1/admin/events/{$id}/cancel", ['reason' => 'Not allowed to do this.'])->assertForbidden();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/events/{$id}/cancel", ['reason' => 'Organizer verification revoked.'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
}

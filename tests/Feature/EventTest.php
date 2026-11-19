<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Notifications\EventChanged;
use App\Notifications\RegistrationChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
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
        $this->as($this->rider())->postJson("/api/v1/admin/events/{$id}/cancel", ['reason' => 'Not allowed to do this.'])->assertUnauthorized();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/events/{$id}/cancel", ['reason' => 'Organizer verification revoked.'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_waitlist_promotion_and_organizer_decisions_notify_the_rider(): void
    {
        Notification::fake();
        $org = $this->organizer();
        $id = $this->published($org, ['capacity' => 1, 'waitlist_enabled' => true]);
        [$a, $b] = [$this->rider(), $this->rider()];
        $this->as($a)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'approved');
        $this->as($b)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'waitlisted');
        Notification::assertNothingSentTo($b);
        $this->as($a)->postJson("/api/v1/events/{$id}/leave")->assertOk();
        Notification::assertSentTo($b, RegistrationChanged::class, fn ($n) => $n->change === 'promoted' && $n->event->id === $id);
        Notification::assertNotSentTo($a, RegistrationChanged::class);

        $req = $this->published($org, ['join_method' => 'approval']);
        [$yes, $no] = [$this->rider(), $this->rider()];
        $this->as($yes)->postJson("/api/v1/events/{$req}/join")->assertJsonPath('status', 'pending');
        $this->as($no)->postJson("/api/v1/events/{$req}/join")->assertJsonPath('status', 'pending');
        $regs = collect($this->as($org)->getJson("/api/v1/events/{$req}/registrations")->json('data'))->keyBy('rider.id');
        $this->as($org)->postJson("/api/v1/events/{$req}/registrations/{$regs[$yes->id]['id']}", ['status' => 'approved'])->assertOk();
        $this->as($org)->postJson("/api/v1/events/{$req}/registrations/{$regs[$no->id]['id']}", ['status' => 'declined'])->assertOk();
        Notification::assertSentTo($yes, RegistrationChanged::class, fn ($n) => $n->change === 'approved');
        Notification::assertSentTo($no, RegistrationChanged::class, fn ($n) => $n->change === 'declined');
        $this->assertSame(['kind' => 'event_registration', 'change' => 'approved', 'event_id' => $req, 'title' => 'Dhulikhel weekend ride', 'link' => "/events/{$req}"],
            (new RegistrationChanged(Event::find($req), 'approved'))->toArray($yes));
    }

    public function test_updates_and_postponement_reach_participants_with_a_reason(): void
    {
        Notification::fake();
        $org = $this->organizer();
        $id = $this->published($org);
        $rider = $this->rider();
        $this->as($rider)->postJson("/api/v1/events/{$id}/join")->assertOk();
        $this->as($rider)->postJson("/api/v1/events/{$id}/updates", ['body' => 'Not my event to update'])->assertForbidden();
        $this->as($org)->postJson("/api/v1/events/{$id}/updates", ['body' => 'Bring a rain cover, the forecast changed.'])->assertCreated();
        Notification::assertSentTo($rider, EventChanged::class, fn ($n) => $n->updateKind === 'update');

        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'postponed'])->assertStatus(422)->assertJsonValidationErrors('message');
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'postponed', 'message' => 'Road closed by a landslide; new date soon.'])
            ->assertOk()->assertJsonPath('data.status', 'postponed')->assertJsonPath('data.updates.0.kind', 'postponement'); // newest first
        Notification::assertSentTo($rider, EventChanged::class, fn ($n) => $n->updateKind === 'postponement');
        $this->as($this->rider())->postJson("/api/v1/events/{$id}/join")->assertStatus(422); // no registrations while postponed
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'completed', 'summary' => 'x'])->assertStatus(422); // postponed cannot complete
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'published'])->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_postponing_sets_new_dates_and_organizers_can_remove_participants(): void
    {
        $org = $this->organizer();
        $id = $this->published($org, ['capacity' => 1, 'waitlist_enabled' => true]);
        $first = $this->rider();
        $second = $this->rider();
        $this->as($first)->postJson("/api/v1/events/{$id}/join")->assertOk()->assertJsonPath('status', 'approved');
        $this->as($second)->postJson("/api/v1/events/{$id}/join")->assertOk()->assertJsonPath('status', 'waitlisted');
        $reg = Event::find($id)->registrations()->where('user_id', $first->id)->value('id');
        $this->as($first)->postJson("/api/v1/events/{$id}/registrations/{$reg}", ['status' => 'declined'])->assertForbidden();
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$reg}", ['status' => 'declined'])->assertOk()->assertJsonPath('status', 'declined');
        $this->assertSame('approved', Event::find($id)->registrations()->where('user_id', $second->id)->value('status')->value); // waitlist moved up
        $this->assertSame('removed', $first->notifications()->latest()->first()->data['change']);

        $start = now()->addMonth()->startOfHour();
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'cancelled', 'message' => 'Not a postponement at all.', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'postponed', 'message' => 'Landslide; moved by a month.', 'starts_at' => $start->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'postponed', 'message' => 'Landslide; moved by a month.', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addDay()->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'postponed');
        $this->assertTrue(Event::find($id)->starts_at->equalTo($start));
    }

    public function test_attendance_and_completion_after_the_start(): void
    {
        $org = $this->organizer();
        $id = $this->published($org);
        [$came, $missed] = [$this->rider(), $this->rider()];
        $this->as($came)->postJson("/api/v1/events/{$id}/join")->assertOk();
        $this->as($missed)->postJson("/api/v1/events/{$id}/join")->assertOk();
        $regs = collect($this->as($org)->getJson("/api/v1/events/{$id}/registrations")->json('data'))->keyBy('rider.id');
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$regs[$came->id]['id']}", ['status' => 'attended'])->assertStatus(422); // not started yet

        Event::whereKey($id)->update(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$regs[$came->id]['id']}", ['status' => 'attended'])->assertOk();
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$regs[$missed->id]['id']}", ['status' => 'did_not_attend'])->assertOk();
        $this->as($org)->postJson("/api/v1/events/{$id}/registrations/{$regs[$missed->id]['id']}", ['status' => 'approved'])->assertStatus(422);
        $this->assertSame(['attended', 'did_not_attend'], [EventRegistration::find($regs[$came->id]['id'])->status->value, EventRegistration::find($regs[$missed->id]['id'])->status->value]);

        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'completed', 'summary' => 'Twelve riders, dry roads, back by four.'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.summary', 'Twelve riders, dry roads, back by four.');
        $this->as($org)->patchJson("/api/v1/events/{$id}", ['title' => 'Edited after the end'])->assertStatus(422);
        $this->as($org)->postJson("/api/v1/events/{$id}/status", ['status' => 'cancelled', 'message' => 'Too late to cancel now.'])->assertStatus(422);
    }

    public function test_invitations_can_be_accepted_declined_and_wait_when_full(): void
    {
        Notification::fake();
        $org = $this->organizer();
        $id = $this->published($org, ['capacity' => 1, 'waitlist_enabled' => true]);
        [$yes, $no, $late] = [$this->rider(['username' => 'inv_yes']), $this->rider(['username' => 'inv_no']), $this->rider(['username' => 'inv_late'])];
        foreach (['inv_yes', 'inv_no', 'inv_late'] as $u) {
            $this->as($org)->postJson("/api/v1/events/{$id}/invitations", ['username' => $u])->assertCreated();
        }
        $this->as($org)->postJson("/api/v1/events/{$id}/invitations", ['username' => 'inv_yes'])->assertStatus(422); // already invited
        $this->as($this->rider())->postJson("/api/v1/events/{$id}/invitations", ['username' => 'inv_yes'])->assertForbidden();

        $this->as($yes)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'approved');
        $this->as($no)->postJson("/api/v1/events/{$id}/leave")->assertOk();
        $this->as($no)->getJson("/api/v1/events/{$id}")->assertJsonPath('data.my_registration', 'declined');
        $this->as($late)->postJson("/api/v1/events/{$id}/join")->assertJsonPath('status', 'waitlisted'); // accepted, but the place is gone
    }
}

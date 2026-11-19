<?php

namespace Tests\Feature;

use App\Enums\VerificationKind;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Destination;
use App\Models\Event;
use App\Models\ServicePoint;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Stage 1: rider workflows whose API existed before their screens (my events, editing, resend, destination page). */
class RiderWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function event(User $org, array $over = []): Event
    {
        $e = new Event;
        $e->forceFill($over + [
            'organizer_id' => $org->id, 'category' => 'ride', 'subtype' => 'weekend ride', 'title' => 'Automated suite ride',
            'description' => 'A ride that exists only inside the automated test suite.', 'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(5), 'meeting_point' => 'Test meeting point', 'status' => 'published',
        ])->save();

        return $e;
    }

    private function contribution(User $author, string $status, array $attrs): Contribution
    {
        $c = new Contribution;
        $c->forceFill($attrs + ['author_id' => $author->id, 'kind' => 'observation', 'body' => 'An observation written only for the automated test suite.',
            'observed_on' => now()->subDays(3), 'status' => $status])->save();

        return $c;
    }

    public function test_my_events_lists_hosting_and_participation_only_for_me(): void
    {
        $org = $this->organizer();
        $draft = $this->event($org, ['status' => 'draft', 'title' => 'Draft only the host sees']);
        $open = $this->event($org);
        $rider = $this->rider();
        $other = $this->rider();
        $this->as($rider)->postJson("/api/v1/events/{$open->id}/join")->assertOk();
        $left = $this->event($org, ['title' => 'Left this one']);
        $this->as($rider)->postJson("/api/v1/events/{$left->id}/join")->assertOk();
        $this->as($rider)->postJson("/api/v1/events/{$left->id}/leave")->assertOk();

        $this->as($org)->getJson('/api/v1/users/me/events')->assertOk()
            ->assertJsonCount(3, 'organizing')->assertJsonFragment(['id' => $draft->id])->assertJsonCount(0, 'participating');
        $mine = $this->as($rider)->getJson('/api/v1/users/me/events')->assertOk()->assertJsonCount(0, 'organizing')->json('participating');
        $this->assertSame([$open->id], array_column($mine, 'id'), 'only events the rider is still part of');
        $this->assertSame('approved', $mine[0]['my_registration']);
        $this->as($other)->getJson('/api/v1/users/me/events')->assertJsonCount(0, 'organizing')->assertJsonCount(0, 'participating');
        $this->as(null)->getJson('/api/v1/users/me/events')->assertUnauthorized();
    }

    public function test_author_edits_a_pending_contribution_and_the_change_is_audited(): void
    {
        $author = $this->rider();
        $payload = ['kind' => 'observation', 'title' => 'Fuel queue at the junction', 'body' => 'A long queue for petrol at the junction in the morning, short by noon.',
            'observed_on' => now()->subDay()->toDateString()];
        $id = $this->as($author)->postJson('/api/v1/contributions', $payload)->assertCreated()->json('data.id');

        $this->as($author)->patchJson("/api/v1/contributions/{$id}", ['title' => 'Fuel queue at the main junction'] + $payload)
            ->assertOk()->assertJsonPath('data.title', 'Fuel queue at the main junction')->assertJsonPath('data.status', 'pending_review');
        $log = AuditLog::where('action', 'contribution.edited')->where('subject_id', $id)->sole();
        $this->assertSame('Fuel queue at the junction', $log->old_data['title']);
        $this->assertSame('Fuel queue at the main junction', $log->new_data['title']);

        // Nobody else edits it: not another rider, not staff (staff never rewrite riders' words).
        $this->as($this->rider())->patchJson("/api/v1/contributions/{$id}", $payload)->assertForbidden();
        $this->as($this->staff('admin'))->patchJson("/api/v1/contributions/{$id}", $payload)->assertUnauthorized(); // staff have no rider session
        // Validation still applies on edit.
        $this->as($author)->patchJson("/api/v1/contributions/{$id}", ['observed_on' => now()->addDay()->toDateString()] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors('observed_on');

        // Once reviewed, it is locked for the author too.
        Contribution::find($id)->forceFill(['status' => 'published'])->save();
        $this->as($author)->patchJson("/api/v1/contributions/{$id}", $payload)->assertForbidden();
        $this->assertSame('Fuel queue at the main junction', Contribution::find($id)->title);
    }

    public function test_resend_confirmation_sends_only_to_unconfirmed_accounts_with_one_generic_answer(): void
    {
        Notification::fake();
        $pending = $this->rider(['email_verified_at' => null]);
        $done = $this->rider();
        $answers = [];
        foreach ([$pending->email, $done->email, 'nobody-here@example.test'] as $email) {
            $answers[] = $this->as(null)->postJson('/api/v1/auth/email/resend', ['email' => $email])->assertOk()->json('message');
        }
        $this->assertCount(1, array_unique($answers), 'the answer must not reveal which addresses exist');
        Notification::assertSentTo($pending, VerifyEmailAddress::class);
        Notification::assertNotSentTo($done, VerifyEmailAddress::class);
        $this->as(null)->postJson('/api/v1/auth/email/resend', ['email' => 'not an email'])->assertStatus(422);
    }

    public function test_resend_confirmation_is_rate_limited(): void
    {
        Notification::fake();
        $email = $this->rider(['email_verified_at' => null])->email;
        $codes = [];
        for ($i = 0; $i < 7; $i++) {
            $codes[] = $this->as(null)->postJson('/api/v1/auth/email/resend', ['email' => $email])->status();
        }
        $this->assertContains(429, $codes);
    }

    public function test_destination_page_lists_published_services_and_reports_only(): void
    {
        $d = Destination::create(['slug' => 'test-town', 'name' => 'Test town', 'status' => 'published']);
        ServicePoint::create(['kind' => 'fuel', 'name' => 'Test fuel stop', 'destination_id' => $d->id, 'status' => 'published', 'last_confirmed_on' => now()->subMonth()]);
        ServicePoint::create(['kind' => 'mechanic', 'name' => 'Draft workshop', 'destination_id' => $d->id, 'status' => 'draft']);
        $services = $this->as(null)->getJson('/api/v1/destinations/test-town')->assertOk()->json('services');
        $this->assertSame(['Test fuel stop'], array_column($services, 'name'));
        $this->assertNotNull($services[0]['last_confirmed_on']);

        $author = $this->rider();
        foreach (['published' => 'Visible report', 'pending_review' => 'Waiting report', 'rejected' => 'Rejected report'] as $status => $title) {
            $this->contribution($author, $status, ['title' => $title, 'destination_id' => $d->id]);
        }
        $titles = array_column($this->as(null)->getJson('/api/v1/contributions?destination=test-town&per_page=5')->assertOk()->json('data'), 'title');
        $this->assertSame(['Visible report'], $titles);
    }

    public function test_destination_search_and_province_filter(): void
    {
        Destination::create(['slug' => 'lake-a', 'name' => 'Lakeside A', 'province' => 'Gandaki', 'district' => 'Kaski', 'status' => 'published']);
        Destination::create(['slug' => 'hill-b', 'name' => 'Hill B', 'province' => 'Bagmati', 'district' => 'Kavre', 'status' => 'published']);
        Destination::create(['slug' => 'draft-c', 'name' => 'Lakeside draft', 'province' => 'Gandaki', 'status' => 'draft']);
        $names = fn (string $q) => array_column($this->as(null)->getJson('/api/v1/destinations?'.$q)->assertOk()->json('data'), 'name');
        $this->assertSame(['Lakeside A'], $names('q=lakeside'));
        $this->assertSame(['Hill B'], $names('q=kavre'), 'district is searchable');
        $this->assertSame(['Lakeside A'], $names('province=Gandaki'));
        $this->assertSame([], $names('q=%25'), 'a LIKE wildcard is matched literally');
    }

    public function test_verified_badge_data_reflects_only_approved_claims(): void
    {
        $author = $this->rider();
        $claim = $this->verified($author, VerificationKind::TripContributor);
        $c = $this->contribution($author, 'published', ['kind' => 'trip', 'title' => 'Badge check trip']);
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonPath('data.author.verified.0.kind', 'trip_contributor');
        $claim->forceFill(['status' => 'suspended'])->save();
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonCount(0, 'data.author.verified');
    }
}

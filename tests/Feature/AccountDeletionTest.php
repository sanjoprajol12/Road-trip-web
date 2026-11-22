<?php

namespace Tests\Feature;

use App\Models\AccountDeletionRequest;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ContributionReport;
use App\Models\Event;
use App\Models\ModerationEvidence;
use App\Models\User;
use App\Notifications\AccountDeletionMail;
use App\Notifications\EventChanged;
use App\Notifications\OwnershipOffer;
use App\Notifications\TripClosed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** Account deletion (Q9 option D, D-01..D-04): request, cooling-off, cancel, choices, transfers, evidence, export. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'password-1234'; // UserFactory

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond(); // dates are compared to the second
        Storage::fake('local');
        // Both jobs are off by default (production); these tests exercise them, so they switch them on.
        config(['mrn.account_deletion.processing_enabled' => true, 'mrn.account_deletion.records_purge_enabled' => true]);
    }

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();
        if (! $u) {
            $this->flushSession();
        }

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function contribution(User $author, string $status = 'published', int $photos = 0): Contribution
    {
        $c = new Contribution(['kind' => 'observation', 'title' => 'Loose stones above the river bend', 'body' => 'Stones on the inner lane after the overnight rain, ride slowly.',
            'observed_on' => now()->subDays(3)->toDateString(), 'details' => ['motorcycle' => 'Test bike']]);
        $c->author_id = $author->id;
        $c->status = $status;
        $c->save();
        for ($i = 0; $i < $photos; $i++) {
            $path = "contributions/{$c->id}/".Str::uuid().'.webp';
            Storage::disk('local')->put($path, 'webp-bytes');
            $c->media()->create(['disk' => 'local', 'path' => $path, 'width' => 10, 'height' => 10, 'size_bytes' => 10, 'alt' => 'Stones on the road']);
        }

        return $c->refresh();
    }

    private function event(User $organizer, array $over = []): Event
    {
        $e = new Event(['category' => 'ride', 'subtype' => 'day_ride', 'title' => 'Sunday ride to Nagarkot', 'description' => 'An easy morning ride with a tea stop.',
            'starts_at' => now()->addDays(40), 'ends_at' => now()->addDays(40)->addHours(6), 'meeting_point' => 'Ring road petrol pump']);
        $e->organizer_id = $organizer->id;
        $e->forceFill($over + ['status' => 'published'])->save();

        return $e;
    }

    private function requestDeletion(User $u, array $choices = []): void
    {
        $this->as($u)->postJson('/api/v1/users/me/deletion', ['password' => self::PW, 'confirm' => true, 'choices' => (object) $choices])->assertCreated();
    }

    private function runDue(int $days = 30): void
    {
        $this->travel($days)->days();
        $this->runJobs();
        $this->flushSession(); // weeks later: earlier sign-ins have expired
    }

    /** The three daily jobs, in their scheduled order. */
    private function runJobs(): void
    {
        $this->artisan('mrn:accounts:process-deletions');
        $this->artisan('mrn:accounts:expire-evidence');
        $this->artisan('mrn:accounts:purge-deletion-records');
    }

    /**
     * Makes a rider's request due by the DATABASE clock (the redaction function checks the database's own now(),
     * which PHP time travel does not move). Allowed here because tests use the owner role; the application role
     * cannot move these dates (trigger account_deletion_requests_guard).
     */
    private function dueByDatabaseClock(User $u): AccountDeletionRequest
    {
        DB::update("update account_deletion_requests set requested_at = now() - interval '31 days', run_after = now() - interval '1 minute' where user_id = ? and status = 'scheduled'", [$u->id]);

        return AccountDeletionRequest::where(['user_id' => $u->id, 'status' => 'scheduled'])->sole();
    }

    private function refusedByDatabase(callable $f, string $label): void
    {
        try {
            DB::transaction(fn () => $f()); // savepoint: the test transaction survives the error
            $this->fail("{$label} was allowed");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** A trip with a viewer who accepted the invitation. @return array{0: string, 1: User} */
    private function groupTrip(User $owner): array
    {
        $viewer = $this->rider(['username' => 'trip_viewer_'.Str::lower(Str::random(4))]);
        $trip = $this->as($owner)->postJson('/api/v1/trips', ['title' => 'Mustang loop', 'starts_on' => now()->addDays(60)->toDateString(), 'ends_on' => now()->addDays(64)->toDateString()])->json('data.id');
        $this->as($owner)->postJson("/api/v1/trips/{$trip}/members", ['username' => $viewer->username, 'role' => 'viewer'])->assertOk();
        $inv = $this->as($viewer)->getJson('/api/v1/users/me/trip-invitations')->json('data.0.id');
        $this->as($viewer)->postJson("/api/v1/trip-invitations/{$inv}/accept")->assertOk();

        return [$trip, $viewer];
    }

    public function test_the_request_needs_the_password_a_confirmation_and_a_choice_for_every_public_contribution(): void
    {
        Notification::fake();
        $rider = $this->rider();
        $public = $this->contribution($rider);
        $pending = $this->contribution($rider, 'pending_review');
        $other = $this->contribution($this->rider());

        // Nothing is pre-selected: the summary lists the choice to make, with no request and no choice yet.
        $this->as($rider)->getJson('/api/v1/users/me/deletion')->assertOk()->assertJsonPath('data.request', null)
            ->assertJsonPath('data.cooling_off_days', 30)->assertJsonCount(2, 'data.contributions')
            ->assertJsonFragment(['id' => $public->id, 'eligible' => true])->assertJsonFragment(['id' => $pending->id, 'eligible' => false]);

        $url = '/api/v1/users/me/deletion';
        $this->as($rider)->postJson($url, ['password' => 'wrong-password', 'confirm' => true, 'choices' => [$public->id => 'delete']])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->as($rider)->postJson($url, ['password' => self::PW, 'choices' => [$public->id => 'delete']])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => (object) []])->assertStatus(422)->assertJsonValidationErrors('choices');
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'keep']])->assertStatus(422);
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'delete', $other->id => 'delete']])->assertStatus(422)->assertJsonValidationErrors('choices');
        // Attempts are limited (5 an hour).
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'delete', $pending->id => 'anonymise']])->assertStatus(429);
        $this->assertSame(0, AccountDeletionRequest::count());
        $this->travel(61)->minutes();
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'delete', $pending->id => 'anonymise']])->assertStatus(422);

        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'anonymise']])->assertCreated()
            ->assertJsonPath('data.run_after', now()->addDays(30)->toIso8601String());
        $this->as($rider)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => [$public->id => 'anonymise']])->assertStatus(422); // only one at a time
        $this->assertTrue($rider->refresh()->isDeletionScheduled());
        Notification::assertSentTo($rider, AccountDeletionMail::class, fn ($n) => $n->kind === 'scheduled');
        $audit = AuditLog::where('action', 'account.deletion_requested')->sole();
        $this->assertStringNotContainsString('Loose stones', json_encode($audit->new_data));
        $this->as(null)->getJson('/api/v1/users/me/deletion')->assertUnauthorized();
    }

    public function test_the_cooling_off_keeps_every_record_stops_taking_part_and_signing_in_does_not_cancel(): void
    {
        Notification::fake();
        $rider = $this->rider(['username' => 'leaving_rider']);
        $c = $this->contribution($rider, 'published', 1);
        $organizer = $this->organizer();
        $event = $this->event($organizer);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $rider->id, 'payload' => '', 'last_activity' => time()]);
        $this->requestDeletion($rider, [$c->id => 'delete']);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']); // other devices are signed out
        $this->assertDatabaseHas('contributions', ['id' => $c->id, 'author_id' => $rider->id]);
        $this->assertTrue(Storage::disk('local')->exists($c->media->first()->path));

        // Participation stops: no posting, confirming, joining; not invitable; profile hidden.
        $this->as($rider)->postJson('/api/v1/contributions', ['kind' => 'observation', 'title' => 'Another report', 'body' => 'Something long enough to pass the checks.', 'observed_on' => now()->toDateString()])
            ->assertForbidden()->assertJsonFragment(['message' => 'Your account is scheduled for deletion on '.now()->addDays(30)->format('j M Y').'. Cancel the deletion in your account to take part again.']);
        $this->as($rider)->postJson("/api/v1/events/{$event->id}/join")->assertForbidden();
        $this->as(null)->getJson('/api/v1/riders/leaving_rider')->assertNotFound();
        $trip = $this->as($organizer)->postJson('/api/v1/trips', ['title' => 'Plan', 'starts_on' => now()->addDays(5)->toDateString(), 'ends_on' => now()->addDays(6)->toDateString()])->json('data.id');
        $this->as($organizer)->postJson("/api/v1/trips/{$trip}/members", ['username' => 'leaving_rider', 'role' => 'viewer'])->assertStatus(422);
        // Reading and export still work.
        $this->as($rider)->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.deletion_scheduled_for', now()->addDays(30)->toIso8601String());

        // Signing in again does not cancel.
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => self::PW])->assertSuccessful();
        $this->assertSame('scheduled', AccountDeletionRequest::sole()->status);

        // Day 29: nothing runs.
        $this->runDue(29);
        $this->assertDatabaseHas('users', ['id' => $rider->id]);
        $this->assertDatabaseHas('contributions', ['id' => $c->id]);
        // Day 30: it runs.
        $this->runDue(1);
        $this->assertDatabaseMissing('users', ['id' => $rider->id]);
        $this->assertSame('done', AccountDeletionRequest::sole()->status);
    }

    public function test_only_the_explicit_cancel_stops_the_deletion_and_it_withdraws_open_offers(): void
    {
        Notification::fake();
        $rider = $this->rider();
        [$trip, $viewer] = $this->groupTrip($rider);
        $this->requestDeletion($rider);
        $offer = $this->as($rider)->postJson('/api/v1/users/me/deletion/offers', ['subject_type' => 'trip', 'subject_id' => $trip, 'username' => $viewer->username])->assertCreated()->json('data.id');

        $this->as($rider)->deleteJson('/api/v1/users/me/deletion', ['password' => 'wrong-password'])->assertStatus(422);
        $this->as($rider)->deleteJson('/api/v1/users/me/deletion', ['password' => self::PW])->assertOk();
        $this->assertFalse($rider->refresh()->isDeletionScheduled());
        $this->assertSame('cancelled', AccountDeletionRequest::sole()->status);
        $this->assertDatabaseHas('ownership_transfers', ['id' => $offer, 'status' => 'withdrawn']);
        $this->as($viewer)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertStatus(422);
        Notification::assertSentTo($rider, AccountDeletionMail::class, fn ($n) => $n->kind === 'cancelled');

        $this->runDue(31);
        $this->assertDatabaseHas('users', ['id' => $rider->id]);
        $this->assertDatabaseHas('trips', ['id' => $trip, 'owner_id' => $rider->id]);
        $this->as($rider)->deleteJson('/api/v1/users/me/deletion', ['password' => self::PW])->assertStatus(422); // nothing left to cancel
    }

    public function test_each_contribution_choice_is_applied_at_the_run_and_photos_follow_it(): void
    {
        Notification::fake();
        $rider = $this->rider(['display_name' => 'Leaving Rider']);
        $delete = $this->contribution($rider, 'published', 1);
        $anon = $this->contribution($rider, 'community_corroborated', 1);
        $noPhotos = $this->contribution($rider, 'outdated', 2);
        $pending = $this->contribution($rider, 'pending_review', 1);
        $rejected = $this->contribution($rider, 'rejected');
        $paths = fn (Contribution $c) => $c->media->pluck('path')->all();
        [$pDelete, $pAnon, $pNoPhotos, $pPending] = [$paths($delete), $paths($anon), $paths($noPhotos), $paths($pending)];
        $rider->notify(new TripClosed('An old notification'));
        $this->requestDeletion($rider, [$delete->id => 'delete', $anon->id => 'anonymise', $noPhotos->id => 'anonymise_without_photos']);
        $this->runDue();

        $this->assertDatabaseMissing('users', ['id' => $rider->id]);
        $this->assertDatabaseMissing('contributions', ['id' => $delete->id]);
        $this->assertDatabaseMissing('contributions', ['id' => $pending->id]);
        $this->assertDatabaseMissing('contributions', ['id' => $rejected->id]);
        $this->assertDatabaseHas('contributions', ['id' => $anon->id, 'author_id' => null, 'status' => 'community_corroborated', 'body' => $anon->body]);
        $this->assertDatabaseHas('contributions', ['id' => $noPhotos->id, 'author_id' => null]);
        $this->assertNotNull(Contribution::find($anon->id)->author_deleted_at);
        $this->assertSame(1, DB::table('contribution_media')->where('contribution_id', $anon->id)->count());
        $this->assertSame(0, DB::table('contribution_media')->where('contribution_id', $noPhotos->id)->count());
        foreach ([...$pDelete, ...$pNoPhotos, ...$pPending] as $p) {
            Storage::disk('local')->assertMissing($p);
        }
        Storage::disk('local')->assertExists($pAnon[0]);
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $rider->id)->count());

        // Public responses carry no trace of the author.
        $json = $this->as(null)->getJson("/api/v1/contributions/{$anon->id}")->assertOk()
            ->assertJsonPath('data.author.display_name', 'Former rider')->assertJsonPath('data.author.id', null)->assertJsonPath('data.author.username', null)->json();
        $this->assertStringNotContainsString('Leaving Rider', json_encode($json));
        $this->assertStringNotContainsString($rider->id, json_encode($json));
        $this->as(null)->getJson('/api/v1/contributions')->assertOk()->assertJsonCount(2, 'data');
        $this->as($this->staff('moderator'))->getJson('/api/v1/admin/contributions?status=community_corroborated')->assertOk();

        // The minimum record: dates, counts and choices by id; no content, no email.
        $req = AccountDeletionRequest::sole();
        $this->assertEquals(['contributions_deleted' => 3, 'contributions_anonymised' => 2, 'photos_removed' => 4], array_intersect_key($req->counts, array_flip(['contributions_deleted', 'contributions_anonymised', 'photos_removed'])));
        $record = json_encode($req->toArray()).json_encode(AuditLog::where('action', 'account.deleted')->sole()->toArray());
        $this->assertStringNotContainsString($rider->email, $record);
        $this->assertStringNotContainsString('Loose stones', $record);
        Notification::assertSentOnDemand(AccountDeletionMail::class, fn ($n, $ch, $notifiable) => $n->kind === 'completed' && $notifiable->routes['mail'] === $rider->email);

        // Idempotent: running again changes nothing.
        $this->runJobs();
        $this->assertSame(1, AuditLog::where('action', 'account.deleted')->count());
    }

    public function test_confirmations_given_are_removed_and_the_last_confirmed_date_is_recomputed_without_touching_the_report(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $someone = $this->rider();
        $report = $this->contribution($this->rider());
        DB::table('contribution_confirmations')->insert([
            ['contribution_id' => $report->id, 'user_id' => $someone->id, 'confirmed_on' => now()->subDays(10)->toDateString(), 'created_at' => now()],
            ['contribution_id' => $report->id, 'user_id' => $leaving->id, 'confirmed_on' => now()->subDays(2)->toDateString(), 'created_at' => now()],
        ]);
        $report->forceFill(['last_confirmed_on' => now()->subDays(2)->toDateString()])->save();
        $expected = now()->subDays(10)->toDateString();
        $this->requestDeletion($leaving);
        $this->runDue();

        $fresh = Contribution::find($report->id);
        $this->assertNotNull($fresh, 'the confirmed report itself is never deleted');
        $this->assertSame('published', $fresh->status->value);
        $this->assertSame($expected, $fresh->last_confirmed_on->toDateString());
        $this->assertSame(1, DB::table('contribution_confirmations')->where('contribution_id', $report->id)->count());
        $this->assertSame(1, AccountDeletionRequest::sole()->counts['confirmations_deleted']);
    }

    public function test_an_event_goes_only_to_an_eligible_organizer_who_accepts(): void
    {
        Notification::fake();
        $leaving = $this->organizer();
        $event = $this->event($leaving);
        $participant = $this->rider();
        $event->registrations()->create(['user_id' => $participant->id, 'status' => 'approved']);
        $plain = $this->rider();
        $suspended = $this->organizer();
        $suspended->forceFill(['suspended_until' => now()->addDays(3)])->save();
        $next = $this->organizer();
        $url = '/api/v1/users/me/deletion/offers';

        $this->as($leaving)->postJson($url, ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $next->username])->assertStatus(422); // request first
        $this->requestDeletion($leaving);
        $this->as($leaving)->getJson('/api/v1/users/me/deletion')->assertJsonPath('data.events.0.outcome', 'transfer_or_cancel')->assertJsonPath('data.events.0.participants', 1);
        $this->as($leaving)->postJson($url, ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $plain->username])->assertStatus(422)->assertJsonValidationErrors('username');
        $this->as($leaving)->postJson($url, ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $suspended->username])->assertStatus(422);
        $this->as($plain)->postJson($url, ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $next->username])->assertStatus(422); // not their event
        $offer = $this->as($leaving)->postJson($url, ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $next->username])->assertCreated()->json('data.id');
        Notification::assertSentTo($next, OwnershipOffer::class, fn ($n) => $n->stage === 'offered');
        $this->assertSame($leaving->id, $event->refresh()->organizer_id, 'nothing moves before acceptance');

        $this->as($plain)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertNotFound(); // someone else's offer
        $this->as($next)->getJson('/api/v1/users/me/ownership-offers')->assertOk()->assertJsonPath('data.0.title', 'Sunday ride to Nagarkot');
        $this->as($next)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertOk();
        $this->assertSame($next->id, $event->refresh()->organizer_id);
        Notification::assertSentTo($participant, EventChanged::class, fn ($n) => $n->updateKind === 'update');
        Notification::assertSentTo($leaving, OwnershipOffer::class, fn ($n) => $n->stage === 'accepted');
        $this->as($next)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertStatus(422); // answered once

        $this->runDue();
        $this->assertDatabaseHas('events', ['id' => $event->id, 'organizer_id' => $next->id, 'status' => 'published']);
        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id, 'user_id' => $participant->id]);
    }

    public function test_an_event_nobody_takes_over_is_cancelled_and_its_riders_are_told(): void
    {
        Notification::fake();
        $leaving = $this->organizer();
        $event = $this->event($leaving);
        $past = $this->event($leaving, ['starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(10)->addHours(4), 'status' => 'completed']);
        $empty = $this->event($leaving);
        $participant = $this->rider();
        $event->registrations()->create(['user_id' => $participant->id, 'status' => 'approved']);
        $next = $this->organizer();
        $this->requestDeletion($leaving);
        $offer = $this->as($leaving)->postJson('/api/v1/users/me/deletion/offers', ['subject_type' => 'event', 'subject_id' => $event->id, 'username' => $next->username])->json('data.id');
        $this->as($next)->postJson("/api/v1/ownership-offers/{$offer}/decline")->assertOk();
        Notification::assertSentTo($leaving, OwnershipOffer::class, fn ($n) => $n->stage === 'declined');
        $this->runDue();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'cancelled', 'organizer_id' => null]);
        $this->assertDatabaseMissing('events', ['id' => $past->id]);
        $this->assertDatabaseMissing('events', ['id' => $empty->id]);
        Notification::assertSentTo($participant, EventChanged::class, fn ($n) => $n->updateKind === 'cancellation');
        $this->as($participant)->getJson("/api/v1/events/{$event->id}")->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.organizer.display_name', 'Former rider')->assertJsonPath('data.is_organizer', false);
        $this->as(null)->getJson('/api/v1/events')->assertOk();
        // The database refuses an organizer-less event that is not cancelled.
        $this->expectException(QueryException::class);
        DB::table('events')->where('id', $event->id)->update(['status' => 'published']);
    }

    public function test_a_place_the_rider_held_on_someone_elses_event_goes_to_the_waitlist(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $waiting = $this->rider();
        $event = $this->event($this->organizer(), ['capacity' => 1, 'status' => 'full', 'waitlist_enabled' => true]);
        $event->registrations()->create(['user_id' => $leaving->id, 'status' => 'approved']);
        $event->registrations()->create(['user_id' => $waiting->id, 'status' => 'waitlisted']);
        $this->requestDeletion($leaving);
        $this->runDue();

        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id, 'user_id' => $waiting->id, 'status' => 'approved']);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'full']);
    }

    public function test_a_group_trip_goes_to_a_consenting_member_even_a_viewer(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        [$trip, $viewer] = $this->groupTrip($leaving);
        $outsider = $this->rider();
        $this->requestDeletion($leaving);
        $url = '/api/v1/users/me/deletion/offers';
        $this->as($leaving)->postJson($url, ['subject_type' => 'trip', 'subject_id' => $trip, 'username' => $outsider->username])->assertStatus(422); // members only
        $offer = $this->as($leaving)->postJson($url, ['subject_type' => 'trip', 'subject_id' => $trip, 'username' => $viewer->username])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('trips', ['id' => $trip, 'owner_id' => $leaving->id]);
        $this->as($viewer)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertOk();
        $this->assertDatabaseHas('trips', ['id' => $trip, 'owner_id' => $viewer->id]);
        $this->as($viewer)->patchJson("/api/v1/trips/{$trip}", ['title' => 'Mustang loop, new owner'])->assertOk();

        $this->runDue();
        $this->assertDatabaseHas('trip_members', ['trip_id' => $trip, 'user_id' => $viewer->id, 'role' => 'owner']);
        $this->assertDatabaseMissing('trip_members', ['trip_id' => $trip, 'user_id' => $leaving->id]);
        $this->assertSame(0, AccountDeletionRequest::sole()->counts['trips_deleted']);
    }

    public function test_a_group_trip_nobody_accepts_is_deleted_and_its_members_are_told(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        [$trip, $viewer] = $this->groupTrip($leaving);
        $solo = $this->as($leaving)->postJson('/api/v1/trips', ['title' => 'Solo plan', 'starts_on' => now()->addDays(3)->toDateString(), 'ends_on' => now()->addDays(4)->toDateString()])->json('data.id');
        $this->requestDeletion($leaving);
        $this->as($leaving)->postJson('/api/v1/users/me/deletion/offers', ['subject_type' => 'trip', 'subject_id' => $solo, 'username' => $viewer->username])->assertStatus(422);
        $this->as($leaving)->postJson('/api/v1/users/me/deletion/offers', ['subject_type' => 'trip', 'subject_id' => $trip, 'username' => $viewer->username])->assertCreated();
        $this->runDue(); // the offer was never answered

        $this->assertDatabaseMissing('trips', ['id' => $trip]);
        $this->assertDatabaseMissing('trips', ['id' => $solo]);
        $this->assertSame(0, DB::table('ownership_transfers')->count()); // the unanswered offer lapsed and went with the account
        Notification::assertSentTo($viewer, TripClosed::class, fn ($n) => $n->title === 'Mustang loop');
        $this->assertDatabaseHas('users', ['id' => $viewer->id]);
    }

    public function test_moderation_evidence_is_restricted_audited_never_extended_and_removed_after_thirty_days(): void
    {
        Notification::fake();
        $leaving = $this->rider(['display_name' => 'Leaving Rider']);
        $reporter = $this->rider();
        $flagged = $this->contribution($leaving, 'flagged', 1);
        $reported = $this->contribution($leaving, 'published');
        ContributionReport::create(['contribution_id' => $reported->id, 'reporter_id' => $reporter->id, 'reason' => 'This looks invented, the road was closed.', 'category' => 'other']);
        $othersPost = $this->contribution($reporter);
        ContributionReport::create(['contribution_id' => $othersPost->id, 'reporter_id' => $leaving->id, 'reason' => 'The date on this report is wrong.', 'category' => 'other']);
        $photo = $flagged->media->first()->path;
        $this->requestDeletion($leaving, [$reported->id => 'anonymise']);
        $this->runDue();

        // The account is gone at day 30; the flagged post is deleted from public view but kept as evidence.
        $this->assertDatabaseMissing('users', ['id' => $leaving->id]);
        $this->assertDatabaseMissing('contributions', ['id' => $flagged->id]);
        $this->assertDatabaseHas('contributions', ['id' => $reported->id, 'author_id' => null]);
        $this->assertSame(3, ModerationEvidence::count());
        Storage::disk('local')->assertExists($photo);
        $this->assertDatabaseMissing('contribution_reports', ['reporter_id' => $leaving->id]);
        $evidence = ModerationEvidence::where('contribution_id', $flagged->id)->sole();
        $this->assertTrue($evidence->held_until->lte(now()->addDays(30)));

        // Restricted: moderators without the permission, riders and guests are refused; the list holds no content.
        $this->as($this->staff('moderator'))->getJson('/api/v1/admin/moderation-evidence')->assertForbidden();
        $this->as($this->staff('moderator'))->getJson("/api/v1/admin/moderation-evidence/{$evidence->id}")->assertForbidden();
        $this->as($reporter)->getJson('/api/v1/admin/moderation-evidence')->assertUnauthorized();
        $admin = $this->staff('admin');
        $list = $this->as($admin)->getJson('/api/v1/admin/moderation-evidence')->assertOk()->assertJsonCount(3, 'data')->json();
        $this->assertStringNotContainsString('Leaving Rider', json_encode($list));
        $this->as($admin)->getJson("/api/v1/admin/moderation-evidence/{$evidence->id}")->assertOk()
            ->assertJsonPath('data.snapshot.contribution.title', 'Loose stones above the river bend')->assertJsonPath('data.snapshot.author.display_name', 'Leaving Rider');
        $this->as($admin)->get("/api/v1/admin/moderation-evidence/{$evidence->id}/files/0")->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'moderation.evidence_viewed')->where('subject_id', $evidence->id)->count());

        // No extension: the database caps a hold at 30 days.
        try {
            DB::transaction(fn () => DB::table('moderation_evidence')->where('id', $evidence->id)->update(['held_until' => now()->addDays(31)])); // savepoint
            $this->fail('a hold was extended');
        } catch (QueryException) {
        }

        // Released early by staff once no longer needed.
        $other = ModerationEvidence::where('contribution_id', $reported->id)->sole();
        $this->as($admin)->postJson("/api/v1/admin/moderation-evidence/{$other->id}/release")->assertOk()->assertJsonPath('data.disposition', 'released');
        $this->as($admin)->getJson("/api/v1/admin/moderation-evidence/{$other->id}")->assertStatus(410);

        // Day 31 after the deletion: the rest expires and the copy of the photo goes.
        $this->runDue(31);
        $this->assertSame(0, ModerationEvidence::whereNotNull('snapshot')->count());
        $this->assertSame(2, ModerationEvidence::where('disposition', 'expired')->count());
        Storage::disk('local')->assertMissing($photo);
        $this->assertSame(1, AuditLog::where('action', 'moderation.evidence_released')->count());
    }

    public function test_a_failed_file_removal_is_kept_and_retried(): void
    {
        Notification::fake();
        $rider = $this->rider();
        $c = $this->contribution($rider, 'published', 1);
        $path = $c->media->first()->path;
        $this->requestDeletion($rider, [$c->id => 'delete']);

        $real = Storage::disk('local');
        $broken = \Mockery::mock($real)->makePartial();
        $broken->shouldReceive('delete')->andReturn(false);
        Storage::set('local', $broken);
        $this->travel(30)->days();
        $this->artisan('mrn:accounts:process-deletions')->assertFailed();

        $this->assertDatabaseMissing('users', ['id' => $rider->id]); // the database step is done
        $this->assertDatabaseMissing('contribution_media', ['path' => $path]);
        $this->assertDatabaseHas('pending_file_deletions', ['path' => $path, 'attempts' => 1]);
        $this->assertTrue($real->exists($path));

        Storage::set('local', $real);
        $this->artisan('mrn:accounts:process-deletions')->assertSuccessful();
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
        $this->assertFalse($real->exists($path));
    }

    public function test_a_failure_during_the_run_rolls_everything_back_and_the_next_run_completes(): void
    {
        Notification::fake();
        $rider = $this->rider();
        $c = $this->contribution($rider, 'published', 1);
        [$trip] = $this->groupTrip($rider);
        $this->requestDeletion($rider, [$c->id => 'delete']);
        Contribution::deleting(function () {
            throw new \RuntimeException('simulated failure');
        });
        $this->travel(30)->days();
        $this->artisan('mrn:accounts:process-deletions')->assertFailed();

        $req = AccountDeletionRequest::sole();
        $this->assertSame(['scheduled', 1, 'RuntimeException'], [$req->status, $req->attempts, $req->last_error]);
        $this->assertDatabaseHas('users', ['id' => $rider->id]);
        $this->assertDatabaseHas('trips', ['id' => $trip]); // deleted before the failure, restored by the rollback
        $this->assertDatabaseHas('contributions', ['id' => $c->id]);
        $this->assertTrue(Storage::disk('local')->exists($c->media->first()->path));
        $this->assertSame(0, DB::table('pending_file_deletions')->count());

        Contribution::flushEventListeners();
        $this->artisan('mrn:accounts:process-deletions')->assertSuccessful();
        $this->assertSame('done', $req->refresh()->status);
        $this->assertDatabaseMissing('users', ['id' => $rider->id]);
    }

    public function test_the_export_holds_only_the_riders_own_data_and_needs_the_password(): void
    {
        Notification::fake();
        $rider = $this->rider(['display_name' => 'Exporting Rider']);
        $c = $this->contribution($rider, 'published', 1);
        $this->as($rider)->postJson('/api/v1/users/me/emergency-contacts', ['name' => 'Sister Contact', 'phone' => '+9779800000001', 'relation' => 'sister'])->assertSuccessful();
        [$trip, $viewer] = $this->groupTrip($rider);
        $this->as($viewer)->postJson('/api/v1/users/me/emergency-contacts', ['name' => 'Private Person', 'phone' => '+9779800000002', 'relation' => 'friend'])->assertSuccessful();

        $this->as(null)->postJson('/api/v1/users/me/export', ['password' => self::PW])->assertUnauthorized();
        $this->as($rider)->postJson('/api/v1/users/me/export', ['password' => 'wrong-password'])->assertStatus(422);
        $this->requestDeletion($rider, [$c->id => 'delete']); // still available during the cooling-off
        $res = $this->as($rider)->post('/api/v1/users/me/export', ['password' => self::PW]);
        $res->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $file = tempnam(sys_get_temp_dir(), 'test-export-');
        file_put_contents($file, $res->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $this->assertNotFalse($zip->locateName('photos/'.$c->media->first()->id.'.webp'));
        $this->assertStringContainsString('Loose stones above the river bend', $zip->getFromName('contributions.json'));
        $this->assertStringContainsString('Sister Contact', $zip->getFromName('emergency_contacts.json'));
        $this->assertStringContainsString($viewer->display_name, $zip->getFromName('trips.json'));
        $this->assertStringNotContainsString('Private Person', $all);
        $this->assertStringNotContainsString($viewer->email, $all);
        $this->assertStringNotContainsString('"password"', $all);
        $this->assertStringNotContainsString($rider->getAuthPassword(), $all);
        $zip->close();
        unlink($file);
        $this->assertSame(1, AuditLog::where('action', 'account.exported')->count());
        $this->assertSame(0, Storage::disk('local')->allFiles('exports') === [] ? 0 : 1, 'nothing is stored');
    }

    public function test_staff_see_deletion_requests_only_with_the_permission_and_never_their_content(): void
    {
        Notification::fake();
        $rider = $this->rider();
        $c = $this->contribution($rider);
        $this->requestDeletion($rider, [$c->id => 'delete']);
        $this->as($this->staff('moderator'))->getJson('/api/v1/admin/account-deletions')->assertForbidden();
        $body = $this->as($this->staff('admin'))->getJson('/api/v1/admin/account-deletions')->assertOk()->assertJsonPath('data.0.status', 'scheduled')->json();
        $this->assertStringNotContainsString($rider->email, json_encode($body));
        $this->assertStringNotContainsString($rider->id, json_encode($body));
        $this->assertStringNotContainsString($c->id, json_encode($body));
    }

    // ---------------------------------------------------------------- owner addendum 2026-11-22 (O8, D-10, D-11)

    public function test_o8_contribution_text_in_the_audit_history_is_redacted_by_the_privileged_function_and_recorded(): void
    {
        Notification::fake();
        $rider = $this->rider();
        $pending = $this->contribution($rider, 'pending_review');
        $this->as($rider)->patchJson("/api/v1/contributions/{$pending->id}", ['kind' => 'observation', 'title' => 'Private first draft title', 'body' => 'My own words about my home village, long enough to pass.', 'observed_on' => now()->subDays(3)->toDateString()])->assertOk();
        $kept = $this->contribution($rider, 'published');
        \App\Support\Audit::record('contribution.correction_added', $kept, ['author_correction' => null], ['author_correction' => 'Correction naming my brother Ram.']);
        $other = $this->contribution($this->rider());
        \App\Support\Audit::record('contribution.edited', $other, ['body' => 'Someone else wrote this text.'], ['body' => 'Someone else rewrote this text.']);
        $edit = AuditLog::where(['action' => 'contribution.edited', 'subject_id' => $pending->id])->sole();
        $before = $edit->only(['id', 'actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'ip_address', 'created_at']);
        $keys = [array_keys($edit->old_data), array_keys($edit->new_data)];
        $this->requestDeletion($rider, [$kept->id => 'anonymise']);
        $req = $this->dueByDatabaseClock($rider);
        $this->runJobs();

        // The entry keeps its id, actor, action, subject, IP and time; only the text fields carry the marker.
        $after = AuditLog::find($edit->id);
        $this->assertEquals($before, $after->only(array_keys($before)));
        $this->assertSame('[redacted]', $after->old_data['body']);
        $this->assertSame('[redacted]', $after->new_data['title']);
        $this->assertSame('[redacted]', $after->new_data['body']);
        $this->assertSame($keys, [array_keys($after->old_data), array_keys($after->new_data)]); // values replaced, nothing removed
        $all = json_encode(AuditLog::all()->toArray());
        foreach (['Private first draft title', 'My own words about my home village', 'my brother Ram'] as $text) {
            $this->assertStringNotContainsString($text, $all);
        }
        $this->assertStringContainsString('Someone else rewrote this text.', $all, 'other riders\' history is untouched');

        // Each redaction is its own immutable entry: which entry, which fields, why; no content and no person.
        $records = AuditLog::where('action', 'audit.redacted')->get();
        $this->assertSame(2, $records->count());
        $mine = $records->firstWhere('subject_id', (string) $edit->id);
        $this->assertSame(['system', null, 'audit_logs'], [$mine->actor_type, $mine->actor_id, $mine->subject_type]);
        $this->assertSame('account_deletion', $mine->new_data['reason']);
        $this->assertSame('contribution.edited', $mine->new_data['entry_action']);
        $this->assertContains('old_data.body', $mine->new_data['fields']);
        $this->assertSame(2, AccountDeletionRequest::sole()->counts['audit_entries_redacted']);

        // After the run the request is done: the function refuses to touch anything for it again.
        $this->refusedByDatabase(fn () => DB::select('select mrn_redact_audit_text(?, ?)', [$edit->id, $req->id]), 'a redaction for a finished request');
        $this->assertSame(2, AuditLog::where('action', 'audit.redacted')->count());
        // The app role still cannot change audit rows itself; it may only call the function.
        $this->assertFalse(DB::selectOne("select has_table_privilege('mrn_app', 'audit_logs', 'update') as ok")->ok);
        $this->assertFalse(DB::selectOne("select has_table_privilege('mrn_app', 'audit_logs', 'delete') as ok")->ok);
        $this->assertTrue(DB::selectOne("select has_function_privilege('mrn_app', 'mrn_redact_audit_text(bigint, uuid)', 'execute') as ok")->ok);
    }

    public function test_d10_deletion_records_keep_only_categories_and_counts_and_go_after_one_year(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        [$trip, $viewer] = $this->groupTrip($leaving);
        $a = $this->contribution($leaving);
        $b = $this->contribution($leaving);
        $this->requestDeletion($leaving, [$a->id => 'delete', $b->id => 'anonymise']);
        $offer = $this->as($leaving)->postJson('/api/v1/users/me/deletion/offers', ['subject_type' => 'trip', 'subject_id' => $trip, 'username' => $viewer->username])->json('data.id');
        $this->as($viewer)->postJson("/api/v1/ownership-offers/{$offer}/accept")->assertOk();
        $staying = $this->rider();
        $this->requestDeletion($staying);
        $this->as($staying)->deleteJson('/api/v1/users/me/deletion', ['password' => self::PW])->assertOk();
        $this->runDue();

        $done = AccountDeletionRequest::where('status', 'done')->sole();
        $this->assertSame(['anonymise' => 1, 'delete' => 1], collect($done->choices)->sortKeys()->all());
        $this->assertStringNotContainsString($a->id, json_encode($done->toArray()));
        $this->assertSame([1, 0], [$done->counts['trips_handed_over'], $done->counts['events_handed_over']]);
        $this->assertSame([], (array) AccountDeletionRequest::where('status', 'cancelled')->sole()->choices);

        // Finished records go one year after they finished; a scheduled request is never purged.
        $this->travel(364)->days(); // the cancelled one finished 394 days ago, the done one 364
        $this->runJobs();
        $this->assertSame(['done'], AccountDeletionRequest::pluck('status')->all());
        $later = $this->rider();
        $this->requestDeletion($later);
        $this->travel(2)->days();
        $this->runJobs();
        $this->assertSame(['scheduled'], AccountDeletionRequest::pluck('status')->all());
        $this->assertSame([1, 1], AuditLog::where('action', 'account.deletion_records_purged')->orderBy('id')->get()->map(fn ($a) => $a->new_data['records'])->all());
    }

    public function test_d11_corroboration_is_recalculated_and_moderator_decisions_are_kept(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $threshold = (int) \App\Support\Settings::get('trust.corroboration_threshold');
        $others = collect(range(1, $threshold))->map(fn () => $this->rider());
        $confirm = function (Contribution $c, $riders) {
            foreach ($riders as $i => $r) {
                DB::table('contribution_confirmations')->insert(['contribution_id' => $c->id, 'user_id' => $r->id, 'confirmed_on' => now()->subDays($i + 1)->toDateString(), 'created_at' => now()]);
            }
        };
        $corroborate = fn (Contribution $c, bool $byThreshold) => \App\Support\Audit::record('contribution.community_corroborated', $c, ['status' => 'published'],
            $byThreshold ? ['status' => 'community_corroborated', 'reason' => 'threshold', 'riders' => $threshold, 'threshold' => $threshold] : ['status' => 'community_corroborated', 'moderation_note' => null]);

        $falls = $this->contribution($this->rider(), 'community_corroborated'); // threshold met only with the leaving rider
        $confirm($falls, [$leaving, ...$others->take($threshold - 1)]);
        $corroborate($falls, true);
        $holds = $this->contribution($this->rider(), 'community_corroborated'); // still enough without them
        $confirm($holds, [$leaving, ...$others]);
        $corroborate($holds, true);
        $byStaff = $this->contribution($this->rider(), 'community_corroborated'); // a moderator's decision
        $confirm($byStaff, [$leaving]);
        $corroborate($byStaff, false);
        $sourced = $this->contribution($this->rider(), 'officially_sourced'); // staff verification is not corroboration
        $confirm($sourced, [$leaving]);

        $this->requestDeletion($leaving);
        $this->runDue();

        $this->assertSame('published', Contribution::find($falls->id)->status->value);
        $this->assertSame('community_corroborated', Contribution::find($holds->id)->status->value);
        $this->assertSame('community_corroborated', Contribution::find($byStaff->id)->status->value);
        $this->assertSame('officially_sourced', Contribution::find($sourced->id)->status->value);
        $this->as(null)->getJson("/api/v1/contributions/{$falls->id}")->assertOk()->assertJsonPath('data.status', 'published');
        $withdrawn = AuditLog::where(['action' => 'contribution.corroboration_withdrawn', 'subject_id' => $falls->id])->sole();
        $this->assertEquals(['status' => 'published', 'reason' => 'confirmations_removed_on_account_deletion', 'riders' => $threshold - 1, 'threshold' => $threshold], $withdrawn->new_data);
        $this->assertSame(1, AccountDeletionRequest::sole()->counts['corroborations_withdrawn']);
    }

    // ---------------------------------------------------------------- session 28: review follow-up (R8, R2, R1, R5, switches, R9)

    public function test_r8_a_moderators_still_accurate_date_survives_the_deletion_of_a_rider_who_confirmed_later(): void
    {
        Notification::fake();
        $report = $this->contribution($this->rider());
        $moderator = $this->staff('moderator');
        $leaving = $this->rider();

        // 1. A moderator marks the report still accurate.
        $this->as($moderator)->postJson("/api/v1/admin/contributions/{$report->id}/confirm")->assertOk();
        $staffDate = today()->toDateString();
        // 2. Two days later a rider confirms it (the shown date moves to theirs).
        $this->travel(2)->days();
        $this->as($leaving)->postJson("/api/v1/contributions/{$report->id}/confirmations")->assertOk();
        $this->assertSame(today()->toDateString(), Contribution::find($report->id)->last_confirmed_on->toDateString());
        // 3. That rider deletes their account. 4. The run removes their confirmation.
        $this->requestDeletion($leaving);
        $this->runDue();
        $this->assertSame(0, DB::table('contribution_confirmations')->where('contribution_id', $report->id)->count());

        // 5. The moderator's date is intact (not erased to null). 6. The status is unchanged.
        $fresh = Contribution::find($report->id);
        $this->assertSame($staffDate, $fresh->last_confirmed_on->toDateString());
        $this->assertSame($staffDate, $fresh->staff_confirmed_on->toDateString());
        $this->assertSame('published', $fresh->status->value);
        $this->as(null)->getJson("/api/v1/contributions/{$report->id}")->assertOk()
            ->assertJsonPath('data.last_confirmed_on', $staffDate)->assertJsonPath('data.status', 'published');
        // Repeated processing changes nothing.
        $this->runJobs();
        $this->assertSame($staffDate, Contribution::find($report->id)->last_confirmed_on->toDateString());
    }

    public function test_r8_a_later_moderator_date_is_never_touched_and_rider_only_dates_still_recalculate(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $other = $this->rider();
        $moderated = $this->contribution($this->rider());
        $ridersOnly = $this->contribution($this->rider());
        $this->as($leaving)->postJson("/api/v1/contributions/{$moderated->id}/confirmations")->assertOk();
        DB::table('contribution_confirmations')->insert(['contribution_id' => $ridersOnly->id, 'user_id' => $other->id, 'confirmed_on' => now()->subDays(6)->toDateString(), 'created_at' => now()]);
        $this->as($leaving)->postJson("/api/v1/contributions/{$ridersOnly->id}/confirmations")->assertOk();
        $this->travel(3)->days();
        $this->flushSession(); // days later: the rider's earlier sign-in has expired
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$moderated->id}/confirm")->assertOk();
        $staffDate = today()->toDateString();
        $expectedRiders = now()->subDays(9)->toDateString(); // the other rider confirmed 6 days before the travel
        $this->requestDeletion($leaving);
        $this->runDue();

        $this->assertSame($staffDate, Contribution::find($moderated->id)->last_confirmed_on->toDateString());
        $this->assertSame($expectedRiders, Contribution::find($ridersOnly->id)->last_confirmed_on->toDateString());
        $this->assertNull(Contribution::find($ridersOnly->id)->staff_confirmed_on);
    }

    public function test_r2_the_redaction_function_accepts_only_entries_of_the_account_whose_deletion_is_due(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $active = $this->rider();
        $mine = $this->contribution($leaving, 'pending_review');
        $theirs = $this->contribution($active, 'pending_review');
        \App\Support\Audit::record('contribution.edited', $mine, ['body' => 'Leaving rider old text.'], ['body' => 'Leaving rider new text.']);
        \App\Support\Audit::record('contribution.edited', $theirs, ['body' => 'Active rider old text.'], ['body' => 'Active rider new text.']);
        $placeId = (string) Str::uuid();
        DB::table('places')->insert(['id' => $placeId, 'slug' => 'r2-suggestion-'.Str::lower(Str::random(6)), 'name' => 'My uncle\'s tea shop', 'category' => 'rest_stop', 'latitude' => 28.2, 'longitude' => 83.9, 'status' => 'pending_review', 'submitted_by' => $leaving->id, 'created_at' => now(), 'updated_at' => now()]);
        \App\Support\Audit::record('place.suggested', 'places', null, ['name' => 'My uncle\'s tea shop', 'status' => 'pending_review'], $placeId);
        $entry = fn ($action, $subject) => AuditLog::where(['action' => $action, 'subject_id' => $subject])->value('id');
        [$eMine, $eTheirs, $ePlace] = [$entry('contribution.edited', $mine->id), $entry('contribution.edited', $theirs->id), $entry('place.suggested', $placeId)];
        $redact = fn ($e, $r) => DB::selectOne('select mrn_redact_audit_text(?, ?) as n', [$e, $r])->n;

        $this->requestDeletion($leaving);
        $req = AccountDeletionRequest::where('user_id', $leaving->id)->sole();
        // Not due yet (by the database clock): refused.
        $this->refusedByDatabase(fn () => $redact($eMine, $req->id), 'a request still in its cooling-off');
        $req = $this->dueByDatabaseClock($leaving);

        // Due: the leaving rider's own entries, including the first version of a place suggestion.
        $this->assertSame(2, $redact($eMine, $req->id));
        $this->assertSame(1, $redact($ePlace, $req->id));
        $this->assertSame('[redacted]', AuditLog::find($ePlace)->new_data['name']);
        // Idempotent: no second change and no second record.
        $this->assertSame(0, $redact($eMine, $req->id));
        $this->assertSame(1, AuditLog::where(['action' => 'audit.redacted', 'subject_id' => (string) $eMine])->count());

        // Wrong account, active account, unrelated or mismatched records, unsupported types: refused.
        $this->refusedByDatabase(fn () => $redact($eTheirs, $req->id), 'another (active) rider\'s contribution under this request');
        $this->refusedByDatabase(fn () => $redact($eTheirs, (string) Str::uuid()), 'an unknown request');
        $this->refusedByDatabase(fn () => $redact(-1, $req->id), 'an unknown entry');
        $this->refusedByDatabase(fn () => $redact(AuditLog::where('action', 'account.deletion_requested')->value('id'), $req->id), 'an unsupported entry type');
        \App\Support\Audit::record('contribution.edited', 'places', ['body' => 'x'], ['body' => 'y'], $placeId); // right id, wrong subject type
        $this->refusedByDatabase(fn () => $redact($entry('contribution.edited', $placeId), $req->id), 'a mismatched subject type');
        $this->requestDeletion($active);
        $activeReq = AccountDeletionRequest::where('user_id', $active->id)->sole();
        $this->refusedByDatabase(fn () => $redact($eTheirs, $activeReq->id), 'an account still in its cooling-off');
        // A request whose dates were moved without a full cooling-off, or whose account is not marked: refused.
        DB::update("update account_deletion_requests set run_after = now() - interval '1 minute' where id = ?", [$activeReq->id]);
        $this->refusedByDatabase(fn () => $redact($eTheirs, $activeReq->id), 'a request shortened below 30 days');
        DB::update("update account_deletion_requests set requested_at = now() - interval '31 days' where id = ?", [$activeReq->id]);
        $active->forceFill(['deletion_scheduled_for' => null])->save();
        $this->refusedByDatabase(fn () => $redact($eTheirs, $activeReq->id), 'an account that is not marked for deletion');
        $this->assertStringContainsString('Active rider new text.', json_encode(AuditLog::find($eTheirs)->new_data));
    }

    public function test_r1_and_r2_the_application_role_cannot_fabricate_redactions_or_due_requests(): void
    {
        // The real application role, on its own connection and in its own transaction (rolled back below).
        $app = DB::connection('pgsql');
        $this->assertSame('mrn_app', $app->selectOne('select current_user as u')->u);
        $refused = function (callable $f, string $label) use ($app) {
            try {
                $app->transaction(fn () => $f());
                $this->fail("{$label} was allowed for the application role");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode(), $label.': '.$e->getMessage());
            }
        };
        $app->beginTransaction();
        try {
            $insert = fn (array $row) => $app->table('audit_logs')->insert($row + ['subject_type' => 'audit_logs', 'subject_id' => '1', 'created_at' => now()]);
            $refused(fn () => $insert(['action' => 'audit.redacted', 'actor_type' => 'user', 'new_data' => json_encode(['reason' => 'account_deletion'])]), 'a fabricated redaction record');
            $refused(fn () => $insert(['action' => 'anything.else', 'actor_type' => 'system']), 'a fabricated system entry');
            $refused(fn () => $app->update('update audit_logs set action = ? where id = (select min(id) from audit_logs)', ['tampered']), 'a direct update');
            $refused(fn () => $app->delete('delete from audit_logs where id = (select min(id) from audit_logs)'), 'a direct delete');
            $insert(['action' => 'probe.normal_entry', 'actor_type' => 'user']); // ordinary audit writes still work
            $this->assertSame(1, $app->table('audit_logs')->where('action', 'probe.normal_entry')->count());

            // A request inserted with dates in the past gets the database clock and a full cooling-off instead.
            $uid = (string) Str::uuid();
            $app->table('users')->insert(['id' => $uid, 'display_name' => 'Probe', 'email' => "probe-{$uid}@example.test", 'password' => 'x', 'deletion_scheduled_for' => '2020-01-01', 'created_at' => now(), 'updated_at' => now()]);
            $rid = (string) Str::uuid();
            $app->table('account_deletion_requests')->insert(['id' => $rid, 'user_id' => $uid, 'status' => 'scheduled', 'requested_at' => '2020-01-01', 'run_after' => '2020-01-31', 'choices' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            // (the columns hold whole seconds, so allow the rounding)
            $r = $app->selectOne('select requested_at > now() - interval \'1 minute\' as fresh, run_after >= now() + interval \'30 days\' - interval \'1 second\' as cooled from account_deletion_requests where id = ?', [$rid]);
            $this->assertTrue($r->fresh && $r->cooled, 'the trigger fixes the dates');
            $refused(fn () => $app->update("update account_deletion_requests set run_after = now() - interval '1 day' where id = ?", [$rid]), 'moving the deletion date');
            $refused(fn () => $app->update("update account_deletion_requests set requested_at = '2020-01-01' where id = ?", [$rid]), 'moving the request date');
            $refused(fn () => $app->update('update account_deletion_requests set user_id = ? where id = ?', [(string) Str::uuid(), $rid]), 'changing the account');
            $refused(fn () => $app->table('account_deletion_requests')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid, 'status' => 'done', 'requested_at' => now(), 'run_after' => now(), 'choices' => '{}', 'created_at' => now(), 'updated_at' => now()]), 'inserting a finished request');
            $app->update("update account_deletion_requests set status = 'cancelled' where id = ?", [$rid]); // a legitimate move
            $refused(fn () => $app->update("update account_deletion_requests set status = 'scheduled' where id = ?", [$rid]), 'reviving a cancelled request');
            // So the function has no due request to accept.
            try {
                $app->transaction(fn () => $app->select('select mrn_redact_audit_text(?, ?)', [1, $rid]));
                $this->fail('redaction under a fabricated request was allowed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('is not due for final deletion', $e->getMessage());
            }
        } finally {
            $app->rollBack();
        }
    }

    public function test_r5_a_failing_run_logs_no_sensitive_detail_and_stays_retryable(): void
    {
        Notification::fake();
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        $rider = $this->rider(['email' => 'secret-rider@example.test']);
        $c = $this->contribution($rider);
        $this->requestDeletion($rider, [$c->id => 'delete']);
        Contribution::deleting(function () {
            throw new \RuntimeException('SQL: delete from contributions where email = secret-rider@example.test; body "My very private text"');
        });
        $this->travel(30)->days();
        $this->artisan('mrn:accounts:process-deletions')->assertFailed();
        // And a failure outside the per-request handler (the evidence step), with an equally sensitive message.
        ModerationEvidence::forceCreate(['contribution_id' => (string) Str::uuid(), 'reason' => 'open_case_on_author_deletion', 'snapshot' => [], 'files' => [],
            'created_at' => now()->subDays(40), 'held_until' => now()->subDays(10)]);
        ModerationEvidence::updating(function () {
            throw new \RuntimeException('secret-rider@example.test My very private text');
        });
        $this->artisan('mrn:accounts:expire-evidence')->assertFailed();

        $all = implode("\n", $logged);
        $this->assertStringNotContainsString('secret-rider@example.test', $all);
        $this->assertStringNotContainsString('My very private text', $all);
        $this->assertStringNotContainsString('delete from contributions', $all);
        $req = AccountDeletionRequest::sole();
        $this->assertStringContainsString($req->id, $all, 'staff can still tell which request failed');
        $this->assertStringContainsString('RuntimeException', $all);
        $this->assertStringContainsString('expire-evidence', $all);
        $this->assertSame(['scheduled', 1], [$req->status, $req->attempts]);

        Contribution::flushEventListeners();
        ModerationEvidence::flushEventListeners();
        $this->artisan('mrn:accounts:process-deletions')->assertSuccessful();
        $this->assertSame('done', $req->refresh()->status);
    }

    public function test_the_two_job_switches_default_off_and_work_independently(): void
    {
        Notification::fake();
        $defaults = (require base_path('config/mrn.php'))['account_deletion'];
        $this->assertSame(['processing_enabled' => false, 'records_purge_enabled' => false], $defaults, 'both off unless the environment enables them');
        $rider = $this->rider();
        $this->requestDeletion($rider);
        $old = AccountDeletionRequest::forceCreate(['user_id' => (string) Str::uuid(), 'status' => 'done', 'requested_at' => now()->subYears(2), 'run_after' => now()->subYears(2)->addDays(30), 'completed_at' => now()->subYears(2)->addDays(30), 'choices' => (object) [], 'counts' => []]);
        $this->travel(31)->days();
        $state = fn () => [User::whereKey($rider->id)->exists(), AccountDeletionRequest::whereKey($old->id)->exists()];

        config(['mrn.account_deletion.processing_enabled' => false, 'mrn.account_deletion.records_purge_enabled' => false]);
        $this->artisan('mrn:accounts:process-deletions')->expectsOutputToContain('switched off')->assertSuccessful();
        $this->artisan('mrn:accounts:purge-deletion-records')->expectsOutputToContain('switched off')->assertSuccessful();
        $this->assertSame([true, true], $state(), 'both off: nothing changes');

        config(['mrn.account_deletion.records_purge_enabled' => true]);
        $this->artisan('mrn:accounts:process-deletions')->assertSuccessful();
        $this->artisan('mrn:accounts:purge-deletion-records')->assertSuccessful();
        $this->assertSame([true, false], $state(), 'purging on does not process deletions');

        $old2 = AccountDeletionRequest::forceCreate(['user_id' => (string) Str::uuid(), 'status' => 'cancelled', 'requested_at' => now()->subYears(2), 'run_after' => now()->subYears(2)->addDays(30), 'cancelled_at' => now()->subYears(2), 'choices' => (object) []]);
        config(['mrn.account_deletion.processing_enabled' => true, 'mrn.account_deletion.records_purge_enabled' => false]);
        $this->artisan('mrn:accounts:process-deletions')->assertSuccessful();
        $this->artisan('mrn:accounts:purge-deletion-records')->assertSuccessful();
        $this->assertFalse(User::whereKey($rider->id)->exists(), 'processing on runs the deletion');
        $this->assertTrue(AccountDeletionRequest::whereKey($old2->id)->exists(), 'processing on does not purge records');
    }

    public function test_r9_the_recount_uses_every_remaining_confirmation_without_the_window_or_a_standing_check(): void
    {
        Notification::fake();
        $leaving = $this->rider();
        $threshold = (int) \App\Support\Settings::get('trust.corroboration_threshold');
        $window = (int) \App\Support\Settings::get('trust.corroboration_window_days');
        $others = collect(range(1, $threshold))->map(fn () => $this->rider());
        $others->first()->forceFill(['suspended_until' => now()->addDays(60)])->save(); // standing is not re-checked
        $report = $this->contribution($this->rider(), 'community_corroborated');
        foreach ([$leaving, ...$others] as $r) {
            DB::table('contribution_confirmations')->insert(['contribution_id' => $report->id, 'user_id' => $r->id,
                'confirmed_on' => now()->subDays($window + 100)->toDateString(), 'created_at' => now()]); // all outside the window
        }
        \App\Support\Audit::record('contribution.community_corroborated', $report, ['status' => 'published'], ['status' => 'community_corroborated', 'reason' => 'threshold', 'riders' => $threshold + 1, 'threshold' => $threshold]);
        $this->requestDeletion($leaving);
        $this->runDue();

        $this->assertSame('community_corroborated', Contribution::find($report->id)->status->value, "{$threshold} remaining confirmations still meet the threshold");
        $this->assertSame(0, AccountDeletionRequest::sole()->counts['corroborations_withdrawn']);
    }

    // ---------------------------------------------------------------- session 29: request ownership and the evidence job

    public function test_s29_a_deletion_can_only_be_requested_for_the_signed_in_riders_own_account(): void
    {
        Notification::fake();
        $a = $this->rider();
        $b = $this->rider();
        DB::table('sessions')->insert(['id' => 'b-device', 'user_id' => $b->id, 'payload' => '', 'last_activity' => time()]);
        $url = '/api/v1/users/me/deletion';

        // Guest: refused, nothing written.
        $this->as(null)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => (object) [], 'user_id' => $b->id])->assertUnauthorized();
        // A staff session never reaches rider routes.
        $this->as($this->staff('admin'))->postJson($url, ['password' => self::STAFF_PASSWORD, 'confirm' => true, 'choices' => (object) [], 'user_id' => $b->id])->assertUnauthorized();
        // B's password through A's session is simply A's wrong password.
        $this->as($a)->postJson($url, ['password' => 'not-a-password', 'confirm' => true, 'choices' => (object) [], 'user_id' => $b->id])->assertStatus(422);
        $this->assertSame(0, AccountDeletionRequest::count());

        // A tampered body naming B schedules A only.
        $this->as($a)->postJson($url, ['password' => self::PW, 'confirm' => true, 'choices' => (object) [], 'user_id' => $b->id, 'email' => $b->email])->assertCreated();
        $this->assertSame([$a->id], AccountDeletionRequest::pluck('user_id')->all());
        $this->assertNull($b->refresh()->deletion_scheduled_for);
        $this->assertDatabaseHas('sessions', ['id' => 'b-device']); // B's devices stay signed in
        Notification::assertSentTo($a, AccountDeletionMail::class);
        Notification::assertNotSentTo($b, AccountDeletionMail::class);
        $audit = AuditLog::where('action', 'account.deletion_requested')->sole();
        $this->assertSame([$a->id, 'user', AccountDeletionRequest::sole()->id], [$audit->actor_id, $audit->actor_type, $audit->subject_id]);

        // B schedules their own; A cannot cancel it (A's cancel acts on A's request only).
        $this->requestDeletion($b);
        $this->as($a)->deleteJson($url, ['password' => self::PW, 'user_id' => $b->id])->assertOk();
        $this->assertSame('cancelled', AccountDeletionRequest::where('user_id', $a->id)->sole()->status);
        $this->assertSame('scheduled', AccountDeletionRequest::where('user_id', $b->id)->sole()->status);
        $this->assertNotNull($b->refresh()->deletion_scheduled_for);
        $this->as($a)->deleteJson($url, ['password' => self::PW, 'user_id' => $b->id])->assertStatus(422); // nothing of A's left to cancel
        $this->assertSame('scheduled', AccountDeletionRequest::where('user_id', $b->id)->sole()->status);
    }

    public function test_s29_a_request_fabricated_by_the_application_role_does_not_touch_the_targets_account(): void
    {
        $app = DB::connection('pgsql'); // the real application role
        $app->beginTransaction();
        try {
            $uid = (string) Str::uuid();
            $app->table('users')->insert(['id' => $uid, 'display_name' => 'Target', 'email' => "target-{$uid}@example.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            $before = (array) $app->table('users')->where('id', $uid)->first();
            $app->table('account_deletion_requests')->insert(['id' => (string) Str::uuid(), 'user_id' => $uid, 'status' => 'scheduled', 'requested_at' => '2020-01-01', 'run_after' => '2020-01-31', 'choices' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $this->assertEquals($before, (array) $app->table('users')->where('id', $uid)->first(), 'the account row is untouched');
            $r = $app->table('account_deletion_requests')->where('user_id', $uid)->first();
            $this->assertTrue($app->selectOne('select ?::timestamp >= now() + interval \'30 days\' - interval \'1 second\' as ok', [$r->run_after])->ok, 'not usable for 30 days');
        } finally {
            $app->rollBack();
        }
    }

    /** An evidence row for a deleted rider's flagged report with one photo; returns [evidence, photo path]. */
    private function heldEvidence(string $choice = 'delete'): array
    {
        $leaving = $this->rider();
        $c = $this->contribution($leaving, $choice === 'delete' ? 'flagged' : 'published', 1);
        if ($choice !== 'delete') {
            ContributionReport::create(['contribution_id' => $c->id, 'reporter_id' => $this->rider()->id, 'reason' => 'Please check this report again.', 'category' => 'other']);
        }
        $path = $c->media->first()->path;
        $this->requestDeletion($leaving, $choice === 'delete' ? [] : [$c->id => $choice]);
        $this->runDue();

        return [ModerationEvidence::where('contribution_id', $c->id)->sole(), $path, $c];
    }

    public function test_s29_evidence_is_kept_until_its_expiry_then_removed_once(): void
    {
        Notification::fake();
        [$e, $path] = $this->heldEvidence();
        $this->assertTrue($e->held_until->equalTo(now()->addDays(30)), 'held exactly 30 days from the deletion');

        $this->travel(29)->days();
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();
        $this->assertNotNull($e->refresh()->snapshot);
        Storage::disk('local')->assertExists($path);
        // Earlier removal only through the release endpoint, with the permission.
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/moderation-evidence/{$e->id}/release")->assertForbidden();
        $this->assertNull($e->refresh()->disposed_at);

        $this->travel(1)->days(); // exactly held_until
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();
        $e->refresh();
        $this->assertSame(['expired', null, null], [$e->disposition, $e->snapshot, $e->files]);
        Storage::disk('local')->assertMissing($path);
        // Repeated runs are safe: nothing changes and nothing is recorded twice.
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();
        $this->assertSame(1, AuditLog::where(['action' => 'moderation.evidence_expired', 'subject_id' => $e->id])->count());
        $this->assertSame('expired', $e->refresh()->disposition);
    }

    public function test_s29_a_photo_still_shown_by_an_anonymised_contribution_is_never_removed_with_the_evidence(): void
    {
        Notification::fake();
        [$e, $path, $c] = $this->heldEvidence('anonymise');
        $this->assertDatabaseHas('contributions', ['id' => $c->id, 'author_id' => null]);
        $this->travel(31)->days();
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();

        $this->assertSame('expired', $e->refresh()->disposition);
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('contribution_media', ['contribution_id' => $c->id, 'path' => $path]);
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
    }

    public function test_s29_a_failed_removal_at_expiry_stays_queued_and_the_next_run_completes_it_without_logging_content(): void
    {
        Notification::fake();
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        [$e, $path] = $this->heldEvidence();
        $real = Storage::disk('local');
        $broken = \Mockery::mock($real)->makePartial();
        $broken->shouldReceive('delete')->andReturn(false);
        Storage::set('local', $broken);
        $this->travel(31)->days();
        $this->artisan('mrn:accounts:expire-evidence')->assertFailed();

        $this->assertSame('expired', $e->refresh()->disposition); // the database step is done
        $this->assertDatabaseHas('pending_file_deletions', ['path' => $path, 'attempts' => 1]);
        $this->assertTrue($real->exists($path));

        Storage::set('local', $real);
        $this->artisan('mrn:accounts:expire-evidence')->assertSuccessful();
        $this->assertFalse($real->exists($path));
        $this->assertSame(0, DB::table('pending_file_deletions')->count());
        $all = implode("\n", $logged);
        foreach (['Loose stones above the river bend', 'Stones on the inner lane', 'Stones on the road'] as $text) {
            $this->assertStringNotContainsString($text, $all);
        }
    }

    // ---------------------------------------------------------------- session 31: Option B (least privilege for deletion)

    private function appRefused($app, callable $f, string $label): void
    {
        try {
            $app->transaction(fn () => $f());
            $this->fail("{$label} was allowed for the application role");
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode(), $label.': '.$e->getMessage());
        }
    }

    public function test_s31_the_application_role_cannot_delete_riders_records_or_evidence_or_write_migrations(): void
    {
        $app = DB::connection('pgsql');
        $this->assertSame('mrn_app', $app->selectOne('select current_user as u')->u);
        $app->beginTransaction();
        try {
            $this->appRefused($app, fn () => $app->delete('delete from users where id = ?', [(string) Str::uuid()]), 'DELETE on users');
            $this->appRefused($app, fn () => $app->statement('truncate users cascade'), 'TRUNCATE users');
            $this->appRefused($app, fn () => $app->delete('delete from account_deletion_requests where id = ?', [(string) Str::uuid()]), 'DELETE on account_deletion_requests');
            $this->appRefused($app, fn () => $app->delete('delete from moderation_evidence where id = ?', [(string) Str::uuid()]), 'DELETE on moderation_evidence');
            $this->appRefused($app, fn () => $app->insert("insert into migrations (migration, batch) values ('2099_01_01_000000_skip_me', 999)"), 'INSERT on migrations');
            $this->appRefused($app, fn () => $app->update('update migrations set batch = batch where id = (select min(id) from migrations)'), 'UPDATE on migrations');
            $this->appRefused($app, fn () => $app->delete('delete from migrations where id = (select max(id) from migrations)'), 'DELETE on migrations');
            $this->assertGreaterThan(0, $app->table('migrations')->count(), 'reading the migrations table still works');
            // What the app legitimately does is unchanged: creating and updating these rows.
            $uid = (string) Str::uuid();
            $app->table('users')->insert(['id' => $uid, 'display_name' => 'Still works', 'email' => "ok-{$uid}@example.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
            $app->table('users')->where('id', $uid)->update(['display_name' => 'Still updates']);
            $this->assertSame('Still updates', $app->table('users')->where('id', $uid)->value('display_name'));
        } finally {
            $app->rollBack();
        }
    }

    public function test_s31_the_deletion_and_purge_functions_accept_only_valid_due_work_from_the_application_role(): void
    {
        // Fixtures must be visible to the application role's own connection, so they are COMMITTED through a second
        // owner connection (outside this test's transaction) and removed again in `finally`.
        config(['database.connections.s31_owner' => config('database.connections.pgsql_owner')]);
        $owner = DB::connection('s31_owner');
        $app = DB::connection('pgsql');
        $users = $requests = [];
        $user = function (bool $marked) use ($owner, &$users) {
            $id = (string) Str::uuid();
            $owner->table('users')->insert(['id' => $id, 'display_name' => 'S31 fixture', 'email' => "s31-{$id}@example.test", 'password' => 'x',
                'deletion_scheduled_for' => $marked ? $owner->selectOne('select localtimestamp as t')->t : null, 'created_at' => now(), 'updated_at' => now()]);

            return $users[] = $id;
        };
        $request = function (string $uid, string $status, string $requested, string $runAfter, ?string $finished = null) use ($owner, &$requests) {
            $id = (string) Str::uuid();
            $owner->insert("insert into account_deletion_requests (id, user_id, status, requested_at, run_after, cancelled_at, completed_at, choices, attempts, created_at, updated_at)
                values (?, ?, ?, localtimestamp + ?::interval, localtimestamp + ?::interval, case when ? = 'cancelled' then localtimestamp + ?::interval end, case when ? = 'done' then localtimestamp + ?::interval end, '{}', 0, now(), now())",
                [$id, $uid, $status, $requested, $runAfter, $status, $finished ?? '0 days', $status, $finished ?? '0 days']);

            return $requests[] = $id;
        };
        $call = fn (string $sql, array $b) => $app->selectOne($sql, $b)->n;
        try {
            $due = $user(true);
            $other = $user(true);
            $unmarked = $user(false);
            $rDue = $request($due, 'scheduled', '-31 days', '-1 minute');
            $rEarly = $request($other, 'scheduled', '-1 day', '+29 days');
            $rShort = $request($other === $due ? $due : $user(true), 'scheduled', '-10 days', '-1 minute'); // due but under 30 days
            $rCancelled = $request($user(true), 'cancelled', '-40 days', '-10 days', '-35 days');
            $rUnmarked = $request($unmarked, 'scheduled', '-31 days', '-1 minute');
            $del = 'select mrn_delete_account(?, ?, ?) as n';

            // Refused: missing, mismatched, premature (also with a forged clock), short, not scheduled, not marked.
            $this->appRefused($app, fn () => $call($del, [(string) Str::uuid(), $due, null]), 'a missing request');
            $this->appRefused($app, fn () => $call($del, [$rDue, $other, null]), 'a request for another account');
            $this->appRefused($app, fn () => $call($del, [$rEarly, $other, null]), 'a premature request');
            $this->appRefused($app, fn () => $call($del, [$rEarly, $other, '2099-01-01 00:00:00']), 'a premature request with a forged clock');
            $this->appRefused($app, fn () => $call($del, [$rShort, $owner->table('account_deletion_requests')->where('id', $rShort)->value('user_id'), null]), 'a request under 30 days');
            $this->appRefused($app, fn () => $call($del, [$rCancelled, $owner->table('account_deletion_requests')->where('id', $rCancelled)->value('user_id'), null]), 'a cancelled request');
            $this->appRefused($app, fn () => $call($del, [$rUnmarked, $unmarked, null]), 'an account not marked for deletion');
            $this->assertSame(count($users), $owner->table('users')->whereIn('id', $users)->count(), 'nothing was deleted by the refused calls');

            // A valid due deletion succeeds as the application role, and records itself.
            $this->assertSame(1, $call($del, [$rDue, $due, null]));
            $this->assertFalse($owner->table('users')->where('id', $due)->exists());
            $this->assertTrue($owner->table('users')->where('id', $other)->exists());
            $this->assertSame(1, $owner->table('audit_logs')->where(['action' => 'account.removed', 'actor_type' => 'system', 'subject_id' => $rDue])->count());
            // Repeating it is refused and changes nothing.
            $this->appRefused($app, fn () => $call($del, [$rDue, $due, null]), 'a repeated deletion');
            $this->assertSame(1, $owner->table('audit_logs')->where(['action' => 'account.removed', 'subject_id' => $rDue])->count());

            // The one-year purge: only records whose completion or cancellation is a year old by the database clock.
            $keep = $user(false);
            $old1 = $request($keep, 'done', '-2 years', '-2 years', '-1 year -1 minute');
            $young = $request($keep, 'done', '-2 years', '-2 years', '-1 year +1 minute');
            $old2 = $request($keep, 'cancelled', '-3 years', '-3 years', '-2 years');
            $ancient = $request($keep, 'scheduled', '-3 years', '+1 day');
            $purge = 'select mrn_purge_deletion_records(?) as n';
            $before = $owner->table('account_deletion_requests')->whereNotIn('id', $requests)->count();
            // The app passes a forged past clock (which would purge nothing): ignored, the database clock purges the two old ones.
            $this->assertSame(2, $call($purge, ['1990-01-01 00:00:00']));
            $this->assertSame(1, $owner->table('audit_logs')->where(['action' => 'account.deletion_records_purged', 'actor_type' => 'system'])
                ->where('created_at', '>=', now()->subHour())->whereRaw("new_data->>'records' = '2'")->count());
            $remaining = $owner->table('account_deletion_requests')->whereIn('id', [$old1, $old2, $young, $ancient])->pluck('id')->all();
            $this->assertEqualsCanonicalizing([$young, $ancient], $remaining, 'the database clock decided, not the forged one');
            $this->assertSame(0, $call($purge, ['2099-01-01 00:00:00']), 'a forged future clock cannot purge younger records');
            $this->assertSame(0, $call($purge, [null]), 'repeating is safe');
            $this->assertEqualsCanonicalizing([$young, $ancient], $owner->table('account_deletion_requests')->whereIn('id', [$old1, $old2, $young, $ancient])->pluck('id')->all());
            $this->assertSame($before, $owner->table('account_deletion_requests')->whereNotIn('id', $requests)->count(), 'no other record was touched');
        } finally {
            $owner->table('account_deletion_requests')->whereIn('id', $requests)->delete();
            $owner->table('users')->whereIn('id', $users)->delete();
            $owner->table('audit_logs')->where('actor_type', 'system')->whereIn('action', ['account.removed', 'account.deletion_records_purged'])
                ->where('created_at', '>=', now()->subHour())->delete(); // fixtures only: keep the other tests' audit counts exact
            DB::purge('s31_owner');
        }
    }

    public function test_s31_the_security_check_passes_as_the_application_role_and_prints_no_secret(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('mrn:security:check-database');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $exit, $out);
        $this->assertStringNotContainsString('FAIL', $out);
        $this->assertSame(13, substr_count($out, 'PASS '));
        foreach (['pgsql', 'pgsql_owner'] as $conn) {
            $secret = (string) config("database.connections.{$conn}.password");
            if ($secret !== '') {
                $this->assertStringNotContainsString($secret, $out);
            }
        }
    }

    public function test_s31_a_production_web_request_drops_owner_credentials_and_alerts_once_without_the_secret(): void
    {
        $secret = (string) config('database.connections.pgsql_owner.password');
        $this->assertNotSame('', $secret, 'tests run with owner credentials, as development does');
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        \App\Support\DatabaseSecurity::guardRuntime(webRequest: true); // not production: nothing happens
        $this->assertSame($secret, config('database.connections.pgsql_owner.password'));

        $this->app['env'] = 'production';
        try {
            \App\Support\DatabaseSecurity::guardRuntime(webRequest: false); // console (release step): kept
            $this->assertSame($secret, config('database.connections.pgsql_owner.password'));
            \App\Support\DatabaseSecurity::guardRuntime(webRequest: true);
            $this->assertNull(config('database.connections.pgsql_owner.password'));
            $this->assertNull(config('database.connections.pgsql_owner.username'));
            config(['database.connections.pgsql_owner.password' => $secret]);
            \App\Support\DatabaseSecurity::guardRuntime(webRequest: true); // a second request in the hour: no second alert
        } finally {
            $this->app['env'] = 'testing';
            config(['database.connections.pgsql_owner.password' => $secret]);
        }
        $critical = array_values(array_filter($logged, fn ($l) => str_starts_with($l, 'critical ')));
        $this->assertCount(1, $critical);
        $this->assertStringContainsString('Owner database credentials are set in the production web runtime', $critical[0]);
        $this->assertStringNotContainsString($secret, implode("\n", $logged));
    }
}

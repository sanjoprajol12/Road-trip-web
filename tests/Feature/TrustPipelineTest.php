<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Destination;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 4: transitions, report categories, corrections, "still accurate", history, offence counts, sources. */
class TrustPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function contribution(User $author, string $status = 'pending_review', string $title = 'Landslide cleared near the bridge'): Contribution
    {
        $c = new Contribution(['kind' => 'observation', 'title' => $title, 'body' => 'The road was open on the day, one lane only for about a hundred metres.', 'observed_on' => now()->subDay()->toDateString()]);
        $c->author_id = $author->id;
        $c->save();
        $c->forceFill(['status' => $status])->save();

        return $c;
    }

    public function test_transitions_are_enforced(): void
    {
        $mod = $this->staff('moderator');
        $c = $this->contribution($this->rider(), 'rejected');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$c->id}/decision", ['status' => 'officially_sourced', 'source_url' => 'https://example.org/notice'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $p = $this->contribution($this->rider());
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$p->id}/decision", ['status' => 'community_corroborated'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$p->id}/decision", ['status' => 'published'])->assertOk();
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$p->id}/decision", ['status' => 'officially_sourced', 'source_url' => 'https://example.org/notice', 'source_title' => 'Road department notice'])
            ->assertOk()->assertJsonPath('data.sources.0.title', 'Road department notice');
        $this->assertSame(1, Source::count());
    }

    public function test_dangerous_reports_lead_the_queue_and_can_be_dismissed(): void
    {
        $author = $this->rider();
        $calm = $this->contribution($author, 'published', 'Older report with one plain complaint');
        $danger = $this->contribution($author, 'published', 'Newer report somebody calls dangerous');
        $this->as($this->rider())->postJson("/api/v1/contributions/{$calm->id}/reports", ['reason' => 'The photo is from another valley.', 'category' => 'wrong'])->assertStatus(202);
        $this->as($this->rider())->postJson("/api/v1/contributions/{$danger->id}/reports", ['reason' => 'Tells riders to cross a flooded ford.', 'category' => 'dangerous'])->assertStatus(202);
        $this->as($this->rider())->postJson("/api/v1/contributions/{$danger->id}/reports", ['reason' => 'Not a real category here.', 'category' => 'boring'])->assertStatus(422);

        $mod = $this->staff('moderator');
        $rows = $this->as($mod)->getJson('/api/v1/admin/contributions?status=published')->assertOk()->json('data');
        $this->assertSame($danger->id, $rows[0]['id']);
        $this->assertTrue($rows[0]['dangerous']);
        $this->assertSame('dangerous', $rows[0]['reports'][0]['category']);
        $this->assertContains('outdated', $rows[0]['next_statuses']);

        $reportId = $rows[0]['reports'][0]['id'];
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$calm->id}/reports/{$reportId}/dismiss")->assertNotFound(); // wrong parent
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$danger->id}/reports/{$reportId}/dismiss")->assertNoContent();
        $this->assertDatabaseHas('contribution_reports', ['id' => $reportId, 'resolution' => 'dismissed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contribution.report_dismissed', 'subject_id' => $danger->id]);
        // Riders never reach the staff endpoints.
        $this->as($this->rider())->postJson("/api/v1/admin/contributions/{$calm->id}/reports/1/dismiss")->assertUnauthorized();
    }

    public function test_correction_request_and_author_addendum(): void
    {
        $author = $this->rider();
        $c = $this->contribution($author, 'published');
        $mod = $this->staff('moderator');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$c->id}/correction-request", ['note' => 'Please add the date the lane reopened.'])->assertOk();
        $this->assertSame(1, $author->notifications()->count());
        $this->as($author)->getJson("/api/v1/contributions/{$c->id}")->assertJsonPath('data.correction_request', 'Please add the date the lane reopened.');
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonMissingPath('data.correction_request');

        // Only the author adds a correction; the original body never changes.
        $this->as($this->rider())->postJson("/api/v1/contributions/{$c->id}/correction", ['correction' => 'Someone else trying to add text.'])->assertForbidden();
        $this->as($author)->postJson("/api/v1/contributions/{$c->id}/correction", ['correction' => 'Both lanes reopened two days later.'])->assertOk();
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonPath('data.author_correction', null)->assertJsonPath('data.body', $c->body);
        $this->as($mod)->getJson('/api/v1/admin/contributions?status=corrections')->assertJsonCount(1, 'data');

        $this->as($mod)->postJson("/api/v1/admin/contributions/{$c->id}/correction-review", ['accept' => true])->assertOk();
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonPath('data.author_correction', 'Both lanes reopened two days later.');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$c->id}/correction-review", ['accept' => true])->assertStatus(422);

        // Pending items are edited directly, not corrected; rejected ones cannot be corrected.
        $pending = $this->contribution($author);
        $this->as($author)->postJson("/api/v1/contributions/{$pending->id}/correction", ['correction' => 'This should use the edit form.'])->assertForbidden();
        $rejected = $this->contribution($this->rider(), 'rejected');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$rejected->id}/correction-request", ['note' => 'Please add the date the lane reopened.'])->assertStatus(422);
    }

    public function test_declined_correction_is_hidden_but_kept_in_the_audit_log(): void
    {
        $author = $this->rider();
        $c = $this->contribution($author, 'published');
        $this->as($author)->postJson("/api/v1/contributions/{$c->id}/correction", ['correction' => 'Unrelated advertising text here.'])->assertOk();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$c->id}/correction-review", ['accept' => false])->assertOk();
        $this->assertNull($c->refresh()->author_correction);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contribution.correction_added', 'subject_id' => $c->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contribution.correction_declined', 'subject_id' => $c->id]);
    }

    public function test_still_accurate_confirmations(): void
    {
        $author = $this->rider();
        $c = $this->contribution($author, 'published');
        $this->as($author)->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertForbidden();
        $other = $this->rider();
        $this->as($other)->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertOk()->assertJsonPath('last_confirmed_on', today()->toDateString());
        $this->as($other)->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertOk(); // idempotent per day
        $this->assertDatabaseCount('contribution_confirmations', 1);
        $this->as(null)->getJson("/api/v1/contributions/{$c->id}")->assertJsonPath('data.last_confirmed_on', today()->toDateString());
        $this->as(null)->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertUnauthorized();

        $pending = $this->contribution($author);
        $this->as($other)->postJson("/api/v1/contributions/{$pending->id}/confirmations")->assertForbidden();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$c->id}/confirm")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'contribution.confirmed', 'subject_id' => $c->id]);
    }

    public function test_history_is_for_moderators_and_offences_are_counted(): void
    {
        $author = $this->rider();
        $c = $this->contribution($author);
        $mod = $this->staff('moderator');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$c->id}/decision", ['status' => 'rejected', 'moderation_note' => 'Not about a road in Nepal.'])->assertOk();
        $this->as($mod)->getJson("/api/v1/admin/contributions/{$c->id}/history")->assertOk()
            ->assertJsonPath('data.0.action', 'contribution.rejected')->assertJsonPath('data.0.note', 'Not about a road in Nepal.')->assertJsonPath('data.0.actor_type', 'staff');
        $this->as($author)->getJson("/api/v1/admin/contributions/{$c->id}/history")->assertUnauthorized();

        $this->contribution($author, 'flagged');
        $row = collect($this->as($this->staff('admin'))->getJson('/api/v1/admin/users?q='.$author->display_name)->assertOk()->json('data'))->firstWhere('id', $author->id);
        $this->assertSame(['rejected' => 1, 'flagged' => 1], $row['offences']);
    }

    public function test_destination_sources_are_structured_and_public(): void
    {
        $admin = $this->staff('admin');
        $id = $this->as($admin)->postJson('/api/v1/admin/destinations', [
            'slug' => 'test-place', 'name' => 'Test place', 'status' => 'published',
            'sources' => [['title' => 'Survey sheet', 'publisher' => 'Survey office', 'url' => 'https://example.org/sheet', 'accessed_on' => today()->toDateString()]],
        ])->assertCreated()->assertJsonPath('data.sources.0.publisher', 'Survey office')->json('data.id');
        $this->as($admin)->postJson('/api/v1/admin/destinations', ['slug' => 'bad-src', 'name' => 'Bad', 'status' => 'draft', 'sources' => [['title' => 'x', 'url' => 'javascript:alert(1)']]])
            ->assertStatus(422)->assertJsonValidationErrors(['sources.0.title', 'sources.0.url']);
        $this->as(null)->getJson('/api/v1/destinations/test-place')->assertOk()->assertJsonPath('data.sources.0.title', 'Survey sheet');

        // Replacing the list removes the orphaned citation and is audited.
        $this->as($admin)->patchJson("/api/v1/admin/destinations/{$id}", ['sources' => []])->assertOk()->assertJsonCount(0, 'data.sources');
        $this->assertSame(0, Source::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'destinations.sources_changed', 'subject_id' => $id]);
        $this->assertSame(0, Destination::find($id)->sources()->count());
    }
}

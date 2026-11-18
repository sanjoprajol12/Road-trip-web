<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContributionTest extends TestCase
{
    use RefreshDatabase;

    private function as(?User $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function segmentId(): string
    {
        $route = RideRoute::create(['slug' => 'test-road', 'name' => 'Test road', 'status' => 'published']);

        return $route->segments()->create(['position' => 1, 'start_name' => 'Point A', 'end_name' => 'Point B', 'surface' => 'mixed'])->id;
    }

    private function report(array $over = []): array
    {
        return $over + ['kind' => 'road_report', 'title' => 'Mud after rain near the bridge', 'body' => 'Slow going for about two kilometres, passable with care on a 150cc bike.',
            'observed_on' => now()->subDays(2)->toDateString(), 'details' => ['surface' => 'dirt', 'motorcycle' => 'Test bike']];
    }

    public function test_submission_rules(): void
    {
        $seg = $this->segmentId();
        $rider = $this->rider();
        $this->as($rider)->postJson('/api/v1/contributions', $this->report())->assertStatus(422)->assertJsonValidationErrors('route_segment_id');
        $this->as($rider)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $seg, 'observed_on' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('observed_on');
        $this->as($rider)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $seg, 'details' => ['admin' => true]]))->assertStatus(422);
        $this->as($this->rider(['email_verified_at' => null]))->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $seg]))->assertForbidden();
        $this->as($this->rider(['suspended_until' => now()->addDay()]))->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $seg]))->assertForbidden();
        $this->as($rider)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $seg]))->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.route.slug', 'test-road');
    }

    public function test_pending_is_hidden_until_moderated_and_text_is_never_edited(): void
    {
        $author = $this->rider();
        $id = $this->as($author)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $this->segmentId()]))->json('data.id');
        $this->as(null)->getJson("/api/v1/contributions/{$id}")->assertForbidden();
        $this->as($this->rider())->getJson("/api/v1/contributions/{$id}")->assertForbidden();
        $this->as(null)->getJson('/api/v1/contributions')->assertJsonCount(0, 'data');
        $this->as($author)->getJson("/api/v1/contributions/{$id}")->assertOk();

        $this->as($this->rider())->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'published'])->assertForbidden();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'rejected', 'moderation_note' => 'short'])->assertStatus(422);
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'officially_sourced'])->assertStatus(422)->assertJsonValidationErrors('source_url');
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'published', 'body' => 'rewritten'])->assertOk();
        $this->as(null)->getJson("/api/v1/contributions/{$id}")->assertOk()->assertJsonPath('data.body', $this->report()['body'])->assertJsonMissingPath('data.moderation_note');
        // Published reports can no longer be edited by their author.
        $this->as($author)->patchJson("/api/v1/contributions/{$id}", $this->report(['title' => 'Changed after review']))->assertForbidden();
    }

    public function test_authors_cannot_moderate_their_own_contributions(): void
    {
        $mod = $this->staff('moderator');
        $id = $this->as($mod)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $this->segmentId()]))->json('data.id');
        $this->as($mod)->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'published'])->assertForbidden();
    }

    public function test_three_reports_flag_a_published_contribution(): void
    {
        $author = $this->rider();
        $id = $this->as($author)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $this->segmentId()]))->json('data.id');
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'published'])->assertOk();
        $this->as($author)->postJson("/api/v1/contributions/{$id}/reports", ['reason' => 'Reporting my own post here.'])->assertForbidden();
        foreach (range(1, 3) as $_) {
            $this->as($this->rider())->postJson("/api/v1/contributions/{$id}/reports", ['reason' => 'The bridge was rebuilt last month.'])->assertStatus(202);
        }
        $this->as(null)->getJson("/api/v1/contributions/{$id}")->assertForbidden();
        $this->as($this->staff('moderator'))->getJson('/api/v1/admin/contributions?status=flagged')->assertJsonPath('data.0.open_reports', 3);
    }

    public function test_photo_uploads_are_checked_reencoded_and_access_controlled(): void
    {
        Storage::fake('local');
        $author = $this->rider();
        $id = $this->as($author)->postJson('/api/v1/contributions', $this->report(['route_segment_id' => $this->segmentId()]))->json('data.id');

        $fake = UploadedFile::fake()->createWithContent('road.jpg', 'this is not an image at all');
        $this->as($author)->postJson("/api/v1/contributions/{$id}/media", ['photo' => $fake, 'alt' => 'Muddy road near the bridge'])->assertStatus(422);
        $html = UploadedFile::fake()->createWithContent('x.png', "\x89PNG\r\n\x1a\n<script>alert(1)</script>");
        $this->as($author)->postJson("/api/v1/contributions/{$id}/media", ['photo' => $html, 'alt' => 'Not really a photo'])->assertStatus(422);
        $this->as($author)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('ok.png', 400, 300), 'alt' => ''])->assertStatus(422);

        $media = $this->as($author)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('ok.png', 2400, 1600), 'alt' => 'Muddy road near the bridge'])
            ->assertCreated()->assertJsonPath('data.width', 2000)->json('data');
        $path = \App\Models\ContributionMedia::find($media['id'])->path;
        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('local')->get($path))[2]);

        $this->as($this->rider())->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('x.png', 300, 300), 'alt' => 'Someone else adds a photo'])->assertForbidden();
        $this->as(null)->get($media['url'])->assertForbidden();
        $this->as($author)->get($media['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_public_search_and_profile(): void
    {
        $author = $this->rider(['username' => 'road_writer']);
        $dest = Destination::create(['slug' => 'pokhara', 'name' => 'Pokhara', 'status' => 'published']);
        $id = $this->as($author)->postJson('/api/v1/contributions', ['kind' => 'trip', 'title' => 'Three days around Pokhara', 'body' => 'A full trip write-up used only by the automated test suite.',
            'observed_on' => now()->subMonth()->toDateString(), 'destination_id' => $dest->id, 'details' => ['duration_days' => 3, 'expenses_npr' => 15000]])->assertCreated()->json('data.id');
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['status' => 'published'])->assertOk();
        $this->as(null)->getJson('/api/v1/contributions?destination=pokhara&q=pokhara')->assertJsonCount(1, 'data');
        $this->as(null)->getJson('/api/v1/contributions?kind=road_report')->assertJsonCount(0, 'data');
        $this->as(null)->getJson('/api/v1/riders/road_writer')->assertOk()->assertJsonMissingPath('data.email')->assertJsonCount(1, 'contributions');
    }
}

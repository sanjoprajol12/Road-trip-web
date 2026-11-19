<?php

namespace Tests\Feature;

use App\Enums\VerificationKind;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    public function test_health_reports_database(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok']);
    }

    public function test_guests_see_published_destinations_only(): void
    {
        Destination::create(['slug' => 'pokhara', 'name' => 'Pokhara', 'province' => 'Gandaki', 'status' => 'published']);
        Destination::create(['slug' => 'secret', 'name' => 'Draft Place', 'status' => 'draft']);
        $this->getJson('/api/v1/destinations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'pokhara');
        $this->getJson('/api/v1/destinations/secret')->assertNotFound();
        $this->getJson('/api/v1/destinations?province=Karnali')->assertJsonCount(0, 'data');
    }

    public function test_content_management_is_staff_only(): void
    {
        $body = ['slug' => 'bandipur', 'name' => 'Bandipur', 'status' => 'draft'];
        $this->as($this->rider())->postJson('/api/v1/admin/destinations', $body)->assertUnauthorized();
        $mod = $this->staff('moderator');
        $id = $this->as($mod)->postJson('/api/v1/admin/destinations', $body)->assertCreated()->json('data.id');
        $this->as($mod)->postJson('/api/v1/admin/destinations', $body + ['slug' => 'Bad Slug'])->assertStatus(422);
        $this->as($mod)->patchJson("/api/v1/admin/destinations/{$id}", ['status' => 'published', 'last_confirmed_on' => now()->toDateString()])->assertOk();

        $route = $this->as($mod)->postJson('/api/v1/admin/routes', ['slug' => 'test-road', 'name' => 'Test road', 'status' => 'published', 'end_destination_id' => $id])->assertCreated()->json('data.id');
        $segs = $this->as($mod)->putJson("/api/v1/admin/routes/{$route}/segments", ['segments' => [
            ['start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved', 'start_lat' => 27.7, 'start_lng' => 85.3, 'end_lat' => 27.8, 'end_lng' => 85.4],
            ['start_name' => 'B', 'end_name' => 'C', 'surface' => 'gravel'],
        ]])->assertOk()->json('data.segments');
        // Reordering keeps segment ids, so reports filed on them stay attached.
        $this->as($mod)->putJson("/api/v1/admin/routes/{$route}/segments", ['segments' => [
            ['id' => $segs[1]['id'], 'start_name' => 'B', 'end_name' => 'C', 'surface' => 'gravel'],
            ['id' => $segs[0]['id'], 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved'],
        ]])->assertOk()->assertJsonPath('data.segments.0.id', $segs[1]['id']);
        $this->as(null)->getJson('/api/v1/routes/test-road')->assertOk()->assertJsonCount(2, 'data.segments');
        // A segment with rider reports cannot be removed: the reports must stay attached.
        $rider = $this->rider();
        $this->as($rider)->postJson('/api/v1/contributions', ['kind' => 'road_report', 'title' => 'Report on segment B to C', 'body' => 'Test report body long enough for the rule.',
            'observed_on' => now()->toDateString(), 'route_segment_id' => $segs[1]['id']])->assertCreated();
        $this->as($mod)->putJson("/api/v1/admin/routes/{$route}/segments", ['segments' => [['id' => $segs[0]['id'], 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved']]])
            ->assertStatus(422)->assertJsonValidationErrors('segments');
        $this->as(null)->getJson('/api/v1/destinations/bandipur')->assertOk()->assertJsonCount(1, 'routes');
    }

    public function test_safety_guides_need_a_matching_expert_before_publishing(): void
    {
        $mod = $this->staff('moderator');
        $id = $this->as($mod)->postJson('/api/v1/admin/guides', ['slug' => 'roadside-tyre-repair', 'title' => 'Roadside tyre repair', 'category' => 'mechanical',
            'body' => str_repeat('Test guide body for the automated suite. ', 3), 'sources' => 'Test source citation'])->assertCreated()->json('data.id');
        $this->as($mod)->postJson("/api/v1/admin/guides/{$id}/publish")->assertStatus(422);
        $this->as(null)->getJson('/api/v1/guides/roadside-tyre-repair')->assertForbidden();

        $medic = $this->rider();
        $this->verified($medic, VerificationKind::ExpertReviewer, 'medical');
        $this->as($medic)->postJson("/api/v1/guides/{$id}/expert-review")->assertForbidden(); // wrong field
        $mechanic = $this->rider();
        $this->verified($mechanic, VerificationKind::ExpertReviewer, 'mechanical');
        $this->as($mechanic)->getJson('/api/v1/guides/roadside-tyre-repair')->assertOk();
        $this->as($mechanic)->postJson("/api/v1/guides/{$id}/expert-review")->assertOk()->assertJsonPath('data.expert_reviewed', true);
        $this->as($mod)->postJson("/api/v1/admin/guides/{$id}/publish")->assertOk();
        $this->as(null)->getJson('/api/v1/guides')->assertJsonCount(1, 'data');
        // Editing the text clears the review and unpublishes.
        $this->as($mod)->patchJson("/api/v1/admin/guides/{$id}", ['body' => str_repeat('Changed body text for the automated suite. ', 3)])->assertOk()
            ->assertJsonPath('data.expert_reviewed', false)->assertJsonPath('data.status', 'draft');
    }

    public function test_the_app_database_role_cannot_change_the_schema(): void
    {
        $this->expectException(QueryException::class);
        DB::connection('pgsql')->statement('create table should_not_exist (id int)');
    }

    public function test_the_app_database_role_cannot_rewrite_the_audit_log(): void
    {
        \App\Support\Audit::record('test.entry', 'tests', null, ['x' => 1], 'one');
        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('audit_logs')->delete();
    }

    public function test_guides_cannot_be_saved_without_sources(): void
    {
        $mod = $this->staff('moderator');
        $base = ['slug' => 'packing-list', 'title' => 'Packing list', 'category' => 'preparation', 'body' => str_repeat('Test guide body for the automated suite. ', 3)];
        $this->as($mod)->postJson('/api/v1/admin/guides', $base)->assertStatus(422)->assertJsonValidationErrors('sources');
        $this->as($mod)->postJson('/api/v1/admin/guides', $base + ['sources' => '   '])->assertStatus(422)->assertJsonValidationErrors('sources');
        $id = $this->as($mod)->postJson('/api/v1/admin/guides', $base + ['sources' => 'Test source citation'])->assertCreated()->json('data.id');
        $this->as($mod)->patchJson("/api/v1/admin/guides/{$id}", ['sources' => ''])->assertStatus(422)->assertJsonValidationErrors('sources');
    }
}

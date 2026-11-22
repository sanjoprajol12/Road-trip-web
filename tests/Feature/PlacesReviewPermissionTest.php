<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceCorrection;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * places.review (owner-approved): the built-in moderator reviews rider place suggestions and corrections, and cannot
 * create, edit, publish, unpublish, archive or otherwise manage official places. Numbers refer to the owner's list.
 */
class PlacesReviewPermissionTest extends TestCase
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

    private function suggestion(User $by, string $status = 'pending_review', string $name = 'Test hidden lake'): Place
    {
        $p = new Place(['slug' => Str::slug($name).'-'.Str::random(4), 'name' => $name, 'category' => 'lake', 'description' => 'Rider text that must never be rewritten by a reviewer.', 'latitude' => 28.0, 'longitude' => 84.0]);
        $p->forceFill(['status' => $status, 'submitted_by' => $by->id])->save();

        return $p;
    }

    private function official(string $status = 'published'): Place
    {
        $p = Place::create(['slug' => 'official-'.Str::random(5), 'name' => 'Official viewpoint', 'category' => 'viewpoint', 'latitude' => 28.1, 'longitude' => 84.1]);
        $p->forceFill(['status' => $status])->save();

        return $p;
    }

    private function body(): array
    {
        return ['name' => 'Moderator try', 'category' => 'lake', 'description' => 'Should never be stored.', 'latitude' => 28.2, 'longitude' => 84.2];
    }

    public function test_moderator_reviews_suggestions_and_corrections(): void
    {
        $mod = $this->staff('moderator');
        $this->assertTrue($mod->hasPermission('places.review'));                              // 1
        $this->assertFalse($mod->hasPermission('places.manage'));
        $this->assertFalse($mod->hasPermission('places.publish'));
        $rider = $this->rider();
        $a = $this->suggestion($rider, 'pending_review', 'Lake A');
        $b = $this->suggestion($rider, 'pending_review', 'Lake B');
        $c = $this->suggestion($rider, 'pending_review', 'Lake C');
        $this->official('draft'); // never listed in the review queue

        $this->as($mod)->getJson('/api/v1/admin/place-suggestions')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.pending', 3); // 2
        $this->as($mod)->getJson("/api/v1/admin/place-suggestions/{$a->id}")->assertOk()->assertJsonPath('data.description', $a->description)
            ->assertJsonPath('data.submitted_by_name', $rider->display_name);

        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$a->id}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'published'); // 4
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$b->id}/decision", ['decision' => 'reject', 'note' => 'short'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$b->id}/decision", ['decision' => 'reject', 'note' => 'This is a private garden, not public.'])->assertOk(); // 5
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$c->id}/decision", ['decision' => 'request_changes', 'note' => 'Please add how to reach it.'])->assertOk(); // 6
        $this->assertSame(['published', 'rejected', 'changes_requested'], [$a->refresh()->status, $b->refresh()->status, $c->refresh()->status]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'place.publish', 'subject_id' => $a->id, 'actor_type' => 'staff']);

        $corr = PlaceCorrection::create(['place_id' => $a->id, 'user_id' => $this->rider()->id, 'body' => 'The path is closed now.']);
        $this->as($mod)->getJson('/api/v1/admin/place-corrections')->assertOk()->assertJsonCount(1, 'data'); // 3
        $this->as($mod)->postJson("/api/v1/admin/place-corrections/{$corr->id}/decision", ['decision' => 'accepted', 'note' => 'Checked.'])->assertOk(); // 7
        $history = collect($this->as($mod)->getJson("/api/v1/admin/place-suggestions/{$a->id}")->json('history'))->pluck('action')->all(); // 8
        $this->assertSame(['place.publish', 'place.correction_accepted'], $history);
    }

    public function test_moderator_cannot_manage_or_run_the_publication_lifecycle(): void
    {
        $mod = $this->staff('moderator');
        $official = $this->official('published');
        $draft = $this->official('draft');
        $rider = $this->rider();
        $pending = $this->suggestion($rider);

        $this->as($mod)->postJson('/api/v1/admin/places', $this->body())->assertForbidden();                         // 9
        $this->as($mod)->putJson("/api/v1/admin/places/{$official->id}", $this->body())->assertForbidden();          // 10
        $this->as($mod)->putJson("/api/v1/admin/places/{$pending->id}", $this->body())->assertForbidden();           // 18: no rewrite of the rider's text
        $this->as($mod)->getJson('/api/v1/admin/places')->assertForbidden();
        $this->as($mod)->postJson("/api/v1/admin/places/{$draft->id}/decision", ['decision' => 'publish'])->assertForbidden();      // 11
        $this->as($mod)->postJson("/api/v1/admin/places/{$official->id}/decision", ['decision' => 'unpublish'])->assertForbidden(); // 12
        $this->as($mod)->postJson("/api/v1/admin/places/{$official->id}/decision", ['decision' => 'archive'])->assertForbidden();   // 13
        // The review route refuses lifecycle decisions and staff-created places.
        foreach (['publish', 'unpublish', 'archive'] as $d) {
            $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$pending->id}/decision", ['decision' => $d])->assertStatus(422);
        }
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$draft->id}/decision", ['decision' => 'approve'])->assertNotFound(); // 11 via review
        $this->as($mod)->getJson("/api/v1/admin/place-suggestions/{$official->id}")->assertNotFound();
        $this->as($mod)->deleteJson("/api/v1/admin/places/{$official->id}")->assertStatus(405);                     // 14: no delete route exists at all
        // 15: no field changes ownership; a decision ignores extra fields.
        $other = $this->rider();
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$pending->id}/decision", ['decision' => 'request_changes', 'note' => 'Please add the access path.', 'submitted_by' => $other->id, 'name' => 'Rewritten'])->assertOk();
        $pending->refresh();
        $this->assertSame([$rider->id, 'Test hidden lake', 'Rider text that must never be rewritten by a reviewer.'], [$pending->submitted_by, $pending->name, $pending->description]); // 15, 18
        // 16: no role or permission management.
        $this->as($mod)->postJson('/api/v1/admin/roles', ['name' => 'Escalated', 'permissions' => ['places.publish']])->assertForbidden();
        $this->as($mod)->patchJson('/api/v1/admin/roles/moderator', ['name' => 'Moderator', 'permissions' => ['places.publish', 'places.review']])->assertForbidden();
        $this->as($mod)->postJson("/api/v1/admin/staff/{$mod->id}/roles", ['role' => 'admin'])->assertForbidden();
        // 21: unrelated staff areas stay closed.
        $this->as($mod)->getJson('/api/v1/admin/settings/mail')->assertForbidden();
        $this->as($mod)->getJson('/api/v1/admin/contact-messages')->assertForbidden();
        $this->as($mod)->getJson('/api/v1/admin/cms/entries/page')->assertForbidden();
    }

    public function test_self_review_transitions_and_stale_decisions_are_refused(): void
    {
        $riderSelf = $this->rider();
        $modSelf = $this->staff('moderator', $riderSelf); // the moderator's own linked rider account
        $own = $this->suggestion($riderSelf);
        $this->as($modSelf)->postJson("/api/v1/admin/place-suggestions/{$own->id}/decision", ['decision' => 'approve'])->assertStatus(422); // 17
        $corr = PlaceCorrection::create(['place_id' => $this->official()->id, 'user_id' => $riderSelf->id, 'body' => 'My own correction.']);
        $this->as($modSelf)->postJson("/api/v1/admin/place-corrections/{$corr->id}/decision", ['decision' => 'accepted', 'note' => 'Accept mine.'])->assertStatus(422);
        $this->assertSame('pending_review', $own->refresh()->status);

        $mod = $this->staff('moderator');
        $sent = $this->suggestion($this->rider(), 'changes_requested');
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$sent->id}/decision", ['decision' => 'approve'])->assertStatus(422); // 19: must be resubmitted first
        $done = $this->suggestion($this->rider(), 'rejected');
        foreach (['approve', 'reject', 'request_changes'] as $d) {
            $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$done->id}/decision", ['decision' => $d, 'note' => 'Trying again after the final decision.'])->assertStatus(422); // 20
        }
        $p = $this->suggestion($this->rider());
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$p->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->as($mod)->postJson("/api/v1/admin/place-suggestions/{$p->id}/decision", ['decision' => 'reject', 'note' => 'A stale second decision.'])->assertStatus(422); // 20: repeated
        $this->assertSame('published', $p->refresh()->status);
        $this->as($mod)->postJson("/api/v1/admin/place-corrections/{$corr->id}/decision", ['decision' => 'rejected', 'note' => 'Not confirmed.'])->assertOk();
        $this->as($mod)->postJson("/api/v1/admin/place-corrections/{$corr->id}/decision", ['decision' => 'accepted', 'note' => 'Again.'])->assertStatus(422);
    }

    public function test_other_roles_keep_their_rights_and_nobody_else_gets_review(): void
    {
        $admin = $this->staff('admin');
        $draft = $this->official('draft');
        $this->as($admin)->postJson('/api/v1/admin/places', $this->body())->assertCreated();                                           // 22
        $this->as($admin)->postJson("/api/v1/admin/places/{$draft->id}/decision", ['decision' => 'publish'])->assertOk();
        $this->as($admin)->postJson("/api/v1/admin/places/{$draft->id}/decision", ['decision' => 'archive'])->assertOk();
        $this->as($admin)->getJson('/api/v1/admin/place-suggestions')->assertOk();
        $this->as($admin)->getJson('/api/v1/admin/place-corrections')->assertOk();

        // 23: custom roles do not receive places.review; only the moderator role holds it.
        DB::table('roles')->insert(['slug' => 'editortest', 'name' => 'Editor test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_slug' => 'editortest', 'permission_key' => 'places.manage']);
        $editor = $this->staff('editortest');
        $this->assertSame(['moderator'], DB::table('permission_role')->where('permission_key', 'places.review')->pluck('role_slug')->all());
        $this->as($editor)->getJson('/api/v1/admin/place-suggestions')->assertForbidden();                                             // 24
        $this->as($editor)->getJson('/api/v1/admin/place-corrections')->assertForbidden();
        $this->as($this->rider())->getJson('/api/v1/admin/place-suggestions')->assertUnauthorized();                                    // 25
        $this->as(null)->getJson('/api/v1/admin/place-suggestions')->assertUnauthorized();                                              // 26
        $this->as(null)->postJson('/api/v1/admin/place-corrections/'.Str::uuid().'/decision', ['decision' => 'accepted', 'note' => 'x'])->assertUnauthorized();

        // 27: permissions are read per request, so granting and revoking apply on the next request.
        DB::table('permission_role')->insert(['role_slug' => 'editortest', 'permission_key' => 'places.review']);
        $this->as(StaffAccount::find($editor->id))->getJson('/api/v1/admin/place-suggestions')->assertOk();
        DB::table('permission_role')->where(['role_slug' => 'editortest', 'permission_key' => 'places.review'])->delete();
        $this->as(StaffAccount::find($editor->id))->getJson('/api/v1/admin/place-suggestions')->assertForbidden();
    }
}

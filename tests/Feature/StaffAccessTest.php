<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_matrix_for_guest_rider_moderator_admin(): void
    {
        // A rider session is never a staff session: riders get 401 on the staff API, like guests.
        $cases = [
            ['/api/v1/admin/overview', 401, 401, 200, 200],
            ['/api/v1/admin/verifications', 401, 401, 200, 200],
            ['/api/v1/admin/contributions', 401, 401, 200, 200],
            ['/api/v1/admin/users', 401, 401, 200, 200],
            ['/api/v1/admin/staff', 401, 401, 200, 200],
            ['/api/v1/admin/audit-logs', 401, 401, 403, 200],
        ];
        $rider = $this->rider();
        $mod = $this->staff('moderator');
        $admin = $this->staff('admin');
        foreach ($cases as [$url, $guest, $r, $m, $a]) {
            foreach ([[null, $guest], [$rider, $r], [$mod, $m], [$admin, $a]] as [$who, $status]) {
                $this->app['auth']->forgetGuards(); // fresh guard per identity
                ($who ? $this->actingAs($who) : $this)->getJson($url)->assertStatus($status);
            }
        }
    }

    public function test_role_changes_are_admin_only_and_need_a_recent_password(): void
    {
        $mod = $this->staff('moderator');
        $admin = $this->staff('admin');
        $target = $this->staff('moderator');
        $rider = $this->rider();
        $this->actingAs($rider)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertUnauthorized();
        $this->actingAs($mod)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertStatus(423);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertOk()->assertJsonPath('roles', ['admin', 'moderator']);
        $this->assertSame(1, AuditLog::where('action', 'role.granted')->where('actor_type', 'staff')->count());
        // Rider accounts never receive a role: there is no staff account with a rider's id.
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/staff/{$rider->id}/roles", ['role' => 'moderator'])->assertNotFound();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $rider->id)->count());
    }

    public function test_role_change_ends_the_target_sessions(): void
    {
        $admin = $this->staff('admin');
        $target = $this->staff('moderator');
        DB::table('staff_sessions')->insert(['id' => 'staff-laptop', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertOk();
        $this->assertFalse(DB::table('staff_sessions')->where('id', 'staff-laptop')->exists());
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/admin/staff/{$admin->id}/roles/admin")->assertStatus(422)->assertJsonValidationErrors('role');
        $second = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/admin/staff/{$second->id}/roles/admin")->assertOk()->assertJsonPath('roles', []);
    }

    public function test_only_admins_see_or_search_email_addresses(): void
    {
        $rider = $this->rider(['email' => 'findme@example.test', 'display_name' => 'Find Me']);
        $this->actingAs($this->staff('moderator'))->getJson('/api/v1/admin/users?q=findme@')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->staff('moderator'))->getJson('/api/v1/admin/users?q=Find')->assertOk()->assertJsonPath('data.0.email', null);
        $this->actingAs($this->staff('admin'))->getJson('/api/v1/admin/users?q=findme@')->assertOk()->assertJsonPath('data.0.email', $rider->email);
        $this->actingAs($this->staff('admin'))->getJson('/api/v1/admin/users?q=%25')->assertOk()->assertJsonCount(0, 'data');
        // Same rule for the staff list.
        $this->actingAs($this->staff('moderator'))->getJson('/api/v1/admin/staff')->assertOk()->assertJsonPath('data.0.email', null);
    }

    public function test_suspension_blocks_writes_and_staff_cannot_suspend_their_own_rider_account(): void
    {
        $mod = $this->staff('moderator');
        $rider = $this->rider();
        $ownRider = $this->rider();
        $linked = $this->staff('moderator', $ownRider);
        $until = now()->addWeek()->toIso8601String();
        // Staff accounts are not rider accounts: the rider endpoint does not know them.
        $this->actingAs($mod)->postJson("/api/v1/admin/users/{$linked->id}/suspension", ['until' => $until, 'reason' => 'Testing staff suspension rule.'])->assertNotFound();
        $this->actingAs($linked)->postJson("/api/v1/admin/users/{$ownRider->id}/suspension", ['until' => $until, 'reason' => 'Suspending my own rider account.'])->assertStatus(422);
        $this->actingAs($mod)->postJson("/api/v1/admin/users/{$rider->id}/suspension", ['until' => $until, 'reason' => 'Repeated spam reports.'])->assertOk();
        $this->actingAs($rider->fresh())->postJson('/api/v1/trips', ['title' => 'Blocked'])->assertForbidden();
        $this->actingAs($rider->fresh())->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.suspended_until', fn ($v) => $v !== null);
        $this->actingAs($mod)->deleteJson("/api/v1/admin/users/{$rider->id}/suspension")->assertNoContent();
        $this->actingAs($rider->fresh())->postJson('/api/v1/trips', ['title' => 'Allowed again'])->assertCreated();
    }

    public function test_audit_log_is_readable_by_admins_only(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/admin/staff/'.$this->staff('moderator')->id.'/roles', ['role' => 'admin'])->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertOk()
            ->assertJsonPath('data.0.action', 'role.granted')->assertJsonPath('data.0.actor_type', 'staff')->assertJsonPath('data.0.actor', $admin->display_name);
    }
}

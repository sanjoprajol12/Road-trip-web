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
        $cases = [
            ['/api/v1/admin/overview', 401, 403, 200, 200],
            ['/api/v1/admin/verifications', 401, 403, 200, 200],
            ['/api/v1/admin/contributions', 401, 403, 200, 200],
            ['/api/v1/admin/users', 401, 403, 200, 200],
            ['/api/v1/admin/audit-logs', 401, 403, 403, 200],
        ];
        $rider = $this->rider();
        $mod = $this->staff('moderator');
        $admin = $this->staff('admin');
        foreach ($cases as [$url, $guest, $r, $m, $a]) {
            foreach ([[null, $guest], [$rider, $r], [$mod, $m], [$admin, $a]] as [$who, $status]) {
                $this->app['auth']->forgetGuards(); // fresh guard per identity
                ($who ? $this->actingAs($who, 'sanctum') : $this)->getJson($url)->assertStatus($status);
            }
        }
    }

    public function test_role_changes_are_admin_only_and_need_a_recent_password(): void
    {
        $mod = $this->staff('moderator');
        $admin = $this->staff('admin');
        $rider = $this->rider();
        $this->actingAs($rider)->postJson("/api/v1/admin/users/{$rider->id}/roles", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($mod)->postJson("/api/v1/admin/users/{$rider->id}/roles", ['role' => 'moderator'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$rider->id}/roles", ['role' => 'moderator'])->assertStatus(423);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/users/{$rider->id}/roles", ['role' => 'moderator'])->assertOk()->assertJsonPath('roles', ['moderator']);
        $this->assertSame(1, AuditLog::where('action', 'role.granted')->count());
    }

    public function test_role_change_ends_the_target_sessions(): void
    {
        $admin = $this->staff('admin');
        $rider = $this->rider();
        DB::table('sessions')->insert(['id' => 'rider-phone', 'user_id' => $rider->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/admin/users/{$rider->id}/roles", ['role' => 'moderator'])->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', 'rider-phone')->exists());
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/admin/users/{$admin->id}/roles/admin")->assertStatus(422)->assertJsonValidationErrors('role');
        $second = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->deleteJson("/api/v1/admin/users/{$second->id}/roles/admin")->assertOk()->assertJsonPath('roles', []);
    }

    public function test_only_admins_see_or_search_email_addresses(): void
    {
        $rider = $this->rider(['email' => 'findme@example.test', 'display_name' => 'Find Me']);
        $this->actingAs($this->staff('moderator'))->getJson('/api/v1/admin/users?q=findme@')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->staff('moderator'))->getJson('/api/v1/admin/users?q=Find')->assertOk()->assertJsonPath('data.0.email', null);
        $this->actingAs($this->staff('admin'))->getJson('/api/v1/admin/users?q=findme@')->assertOk()->assertJsonPath('data.0.email', $rider->email);
        $this->actingAs($this->staff('admin'))->getJson('/api/v1/admin/users?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_suspension_blocks_writes_and_staff_cannot_suspend_admins_unless_admin(): void
    {
        $mod = $this->staff('moderator');
        $rider = $this->rider();
        $otherStaff = $this->staff('admin');
        $this->actingAs($mod)->postJson("/api/v1/admin/users/{$otherStaff->id}/suspension", ['until' => now()->addWeek()->toIso8601String(), 'reason' => 'Testing staff suspension rule.'])
            ->assertStatus(422);
        $this->actingAs($mod)->postJson("/api/v1/admin/users/{$rider->id}/suspension", ['until' => now()->addWeek()->toIso8601String(), 'reason' => 'Repeated spam reports.'])->assertOk();
        $this->actingAs($rider->fresh())->postJson('/api/v1/trips', ['title' => 'Blocked'])->assertForbidden();
        $this->actingAs($rider->fresh())->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.suspended_until', fn ($v) => $v !== null);
        $this->actingAs($mod)->deleteJson("/api/v1/admin/users/{$rider->id}/suspension")->assertNoContent();
        $this->actingAs($rider->fresh())->postJson('/api/v1/trips', ['title' => 'Allowed again'])->assertCreated();
    }

    public function test_audit_log_is_readable_by_admins_only(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/admin/users/'.$this->rider()->id.'/roles', ['role' => 'moderator'])->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonPath('data.0.action', 'role.granted');
    }
}

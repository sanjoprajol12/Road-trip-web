<?php

namespace Tests\Feature;

use App\Enums\VerificationKind;
use App\Models\StaffAccount;
use App\Models\User;
use App\Notifications\StaffPasswordReset;
use App\Notifications\StaffInvitationMail;
use App\Support\Permissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Stage 2: configurable roles built from code-defined permissions, staff invitations and account controls. */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u, bool $confirmed = false)
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession(); // a password confirmation from an earlier call must not carry over
        if (! $u) {
            return $this;
        }

        return $confirmed ? $this->actingAs($u)->withSession(['auth.password_confirmed_at' => time()]) : $this->actingAs($u);
    }

    private function role(string $name, array $permissions): string
    {
        return $this->as($this->staff('admin'), true)->postJson('/api/v1/admin/roles', ['name' => $name, 'permissions' => $permissions])->assertCreated()->json('data.slug');
    }

    private function withRole(string $slug): StaffAccount
    {
        return $this->staff($slug);
    }

    /** A staff account with no role yet (as after an invitation whose roles were all deleted). */
    private function staffWithoutRole(): StaffAccount
    {
        return StaffAccount::forceCreate(['display_name' => 'No role yet', 'email' => 'norole-'.uniqid().'@example.test', 'password' => self::STAFF_PASSWORD]);
    }

    public function test_registry_is_synced_and_system_roles_keep_their_old_rights(): void
    {
        $this->assertEqualsCanonicalizing(Permissions::keys(), DB::table('permissions')->pluck('key')->all());
        $mod = $this->staff('moderator');
        $this->assertEqualsCanonicalizing(config('permissions.defaults.moderator'), $mod->permissions());
        $this->assertEqualsCanonicalizing(Permissions::keys(), $this->staff('admin')->permissions());
        // A rider account passes no staff gate at all.
        $rider = $this->rider();
        foreach (array_merge(['staff', 'admin'], Permissions::keys()) as $gate) {
            $this->assertFalse(Gate::forUser($rider)->allows($gate), "a rider passed {$gate}");
        }
        // Moderators still cannot reach admin-only areas.
        foreach (['/api/v1/admin/audit-logs', '/api/v1/admin/permissions', '/api/v1/admin/staff-invitations'] as $url) {
            $this->as($mod)->getJson($url)->assertForbidden();
        }
    }

    public function test_a_custom_role_reaches_exactly_its_permissions(): void
    {
        $slug = $this->role('Content editor', ['content.destinations.manage', 'overview.view']);
        $this->assertSame('content_editor', $slug);
        $editor = $this->withRole($slug);
        $this->as($editor)->getJson('/api/v1/admin/destinations')->assertOk();
        $this->as($editor)->postJson('/api/v1/admin/destinations', ['slug' => 'perm-test', 'name' => 'Permission test', 'status' => 'draft'])->assertCreated();
        $this->as($editor)->getJson('/api/v1/admin/overview')->assertOk();
        foreach (['/api/v1/admin/routes', '/api/v1/admin/verifications', '/api/v1/admin/contributions', '/api/v1/admin/users', '/api/v1/admin/events', '/api/v1/admin/audit-logs'] as $url) {
            $this->as($editor)->getJson($url)->assertForbidden();
        }
        $this->as($editor)->getJson('/api/v1/admin/auth/session')->assertJsonPath('data.permissions', ['content.destinations.manage', 'overview.view']);
    }

    public function test_multiple_roles_add_up_and_changes_apply_on_the_next_request(): void
    {
        $a = $this->role('Route keeper', ['content.routes.manage']);
        $b = $this->role('Guide writer', ['guides.manage']);
        $u = $this->withRole($a);
        DB::table('staff_account_roles')->insert(['staff_account_id' => $u->id, 'role' => $b, 'granted_at' => now()]);
        $this->as($u)->getJson('/api/v1/admin/routes')->assertOk();
        $this->as($u)->getJson('/api/v1/admin/guides')->assertOk();

        $this->as($this->staff('admin'), true)->patchJson("/api/v1/admin/roles/{$a}", ['name' => 'Route keeper', 'permissions' => []])->assertOk();
        $this->as($u->fresh())->getJson('/api/v1/admin/routes')->assertForbidden();
        $this->as($u->fresh())->getJson('/api/v1/admin/guides')->assertOk();
    }

    public function test_role_rules_system_roles_password_and_no_escalation(): void
    {
        $admin = $this->staff('admin');
        $this->as($admin)->postJson('/api/v1/admin/roles', ['name' => 'No confirm', 'permissions' => []])->assertStatus(423); // needs fresh password
        $this->as($admin, true)->postJson('/api/v1/admin/roles', ['name' => 'Bad', 'permissions' => ['made.up']])->assertStatus(422)->assertJsonValidationErrors('permissions.0');
        $this->as($admin, true)->postJson('/api/v1/admin/roles', ['name' => 'Moderator', 'permissions' => []])->assertStatus(422); // name taken
        $this->as($admin, true)->deleteJson('/api/v1/admin/roles/moderator')->assertStatus(422);
        $this->as($admin, true)->deleteJson('/api/v1/admin/roles/admin')->assertStatus(422);
        $this->as($admin, true)->patchJson('/api/v1/admin/roles/admin', ['name' => 'Administrator', 'permissions' => []])->assertStatus(422);
        $this->as($admin, true)->patchJson('/api/v1/admin/roles/moderator', ['name' => 'Renamed', 'permissions' => ['overview.view']])->assertOk();
        $this->assertSame('Moderator', DB::table('roles')->where('slug', 'moderator')->value('name'), 'system role names stay');

        // A role manager without audit.view cannot create, widen or narrow a role around audit.view.
        $manager = $this->withRole($this->role('Role manager', ['roles.manage', 'users.view']));
        $this->as($manager, true)->postJson('/api/v1/admin/roles', ['name' => 'Sneaky', 'permissions' => ['audit.view']])->assertStatus(422);
        $auditors = $this->role('Auditors', ['audit.view']);
        $this->as($manager, true)->patchJson("/api/v1/admin/roles/{$auditors}", ['name' => 'Auditors', 'permissions' => []])->assertStatus(422);
        $this->as($manager, true)->postJson('/api/v1/admin/roles', ['name' => 'Viewers', 'permissions' => ['users.view']])->assertCreated();

        // In use: refused; unused: deleted and audited.
        $used = $this->role('In use', []);
        $this->withRole($used);
        $this->as($admin, true)->deleteJson("/api/v1/admin/roles/{$used}")->assertStatus(422);
        $this->as($admin, true)->deleteJson('/api/v1/admin/roles/viewers')->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'subject_id' => 'viewers']);
    }

    public function test_giving_roles_cannot_escalate_and_verification_never_grants_staff_rights(): void
    {
        $granter = $this->withRole($this->role('People lead', ['users.roles.manage', 'users.view', 'contributions.moderate']));
        $target = $this->staffWithoutRole();
        $this->as($granter, true)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'admin'])->assertStatus(422);
        $this->as($granter, true)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'moderator'])->assertStatus(422); // moderator has more
        $small = $this->role('Moderation only', ['contributions.moderate']);
        $this->as($granter, true)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => $small])->assertOk()->assertJsonPath('roles', [$small]);
        $this->as($granter, true)->postJson("/api/v1/admin/staff/{$target->id}/roles", ['role' => 'no_such_role'])->assertStatus(422);

        $rider = $this->rider();
        foreach ([VerificationKind::EventOrganizer, VerificationKind::TripContributor, VerificationKind::Identity] as $kind) {
            $this->verified($rider, $kind);
        }
        $this->verified($rider, VerificationKind::ExpertReviewer, 'mechanical');
        foreach (Permissions::keys() as $key) {
            $this->assertFalse(Gate::forUser($rider->fresh())->allows($key), "verification granted {$key}");
        }
        $this->as($rider)->getJson('/api/v1/admin/overview')->assertUnauthorized(); // a rider session is never a staff session
    }

    public function test_staff_invitation_for_a_new_person(): void
    {
        Notification::fake();
        $admin = $this->staff('admin');
        $this->as($admin)->postJson('/api/v1/admin/staff-invitations', ['email' => 'new.staff@example.test', 'roles' => ['moderator']])->assertStatus(423);
        $this->as($admin, true)->postJson('/api/v1/admin/staff-invitations', ['email' => 'New.Staff@example.test', 'roles' => ['moderator']])->assertCreated();
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, StaffInvitationMail::class, function ($n, $channels, $notifiable) use (&$token) {
            $token = $n->token;

            return $notifiable->routes['mail'] === 'new.staff@example.test' && $n->roleNames === ['Moderator']
                && str_starts_with($n->toMail($notifiable)->actionUrl, config('mrn.admin_url').'/accept-invitation?');
        });
        $this->assertDatabaseMissing('staff_invitations', ['token_hash' => $token]); // only the hash is stored
        $this->as($admin)->getJson('/api/v1/admin/staff-invitations')->assertJsonPath('data.0.email', 'new.staff@example.test');

        $accept = fn (array $over = []) => $this->as(null)->postJson('/api/v1/admin/auth/invitations/accept', $over + ['token' => $token, 'email' => 'new.staff@example.test', 'password' => 'a-good-password', 'display_name' => 'New Staff']);
        $accept(['email' => 'someone.else@example.test'])->assertStatus(422)->assertJsonValidationErrors('token');
        $accept(['display_name' => null])->assertStatus(422)->assertJsonValidationErrors('display_name');
        $accept(['password' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $accept()->assertOk();
        $staff = StaffAccount::where('email', 'new.staff@example.test')->sole();
        $this->assertSame(['moderator'], $staff->staffRoles());
        $this->assertFalse(User::where('email', 'new.staff@example.test')->exists(), 'an invitation never creates a rider account');
        $accept()->assertStatus(422)->assertJsonValidationErrors('token'); // single use
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => 'new.staff@example.test', 'password' => 'a-good-password'])->assertOk();
        // The staff password is not a rider credential.
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => 'new.staff@example.test', 'password' => 'a-good-password'])->assertStatus(422);
    }

    public function test_staff_invitation_for_an_existing_account_needs_its_password_and_expires(): void
    {
        Notification::fake();
        $admin = $this->staff('admin');
        $existing = $this->staffWithoutRole();
        $existing->forceFill(['email' => 'existing.staff@example.test'])->save();
        $tokens = [];
        foreach (['moderator', 'moderator'] as $r) {
            $this->as($admin, true)->postJson('/api/v1/admin/staff-invitations', ['email' => 'existing.staff@example.test', 'roles' => [$r]])->assertCreated();
        }
        Notification::assertSentTimes(StaffInvitationMail::class, 2);
        Notification::assertSentTo(new AnonymousNotifiable, StaffInvitationMail::class, function ($n) use (&$tokens) {
            $tokens[] = $n->token;

            return true;
        });
        [$old, $new] = $tokens;
        $body = fn ($t, $pw) => ['token' => $t, 'email' => 'existing.staff@example.test', 'password' => $pw];
        $accept = fn ($t, $pw) => $this->as(null)->postJson('/api/v1/admin/auth/invitations/accept', $body($t, $pw));
        $accept($old, self::STAFF_PASSWORD)->assertStatus(422)->assertJsonValidationErrors('token'); // replaced
        $accept($new, 'wrong-password')->assertStatus(422)->assertJsonValidationErrors('password');
        DB::table('staff_invitations')->whereNull('revoked_at')->update(['expires_at' => now()->subMinute()]);
        $accept($new, self::STAFF_PASSWORD)->assertStatus(422)->assertJsonValidationErrors('token');
        $this->assertSame([], $existing->fresh()->staffRoles());

        // An invitation to a rider's address creates a separate staff account; the rider account is untouched.
        $rider = $this->rider(['email' => 'rider@example.test']);
        $this->as($admin, true)->postJson('/api/v1/admin/staff-invitations', ['email' => 'rider@example.test', 'roles' => ['moderator']])->assertCreated();
        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, StaffInvitationMail::class, function ($n, $c, $notifiable) use (&$token) {
            if ($notifiable->routes['mail'] === 'rider@example.test') {
                $token = $n->token;
            }

            return true;
        });
        // The rider's own password is not accepted as an existing staff password: a new staff password is needed.
        $this->as(null)->postJson('/api/v1/admin/auth/invitations/accept', ['token' => $token, 'email' => 'rider@example.test', 'password' => 'short'])->assertStatus(422);
        $this->as(null)->postJson('/api/v1/admin/auth/invitations/accept', ['token' => $token, 'email' => 'rider@example.test', 'password' => 'a-separate-staff-password', 'display_name' => 'Rider Staff'])->assertOk();
        $this->assertNull(StaffAccount::where('email', 'rider@example.test')->sole()->rider_user_id, 'accounts are never linked automatically');
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $rider->id)->count());
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => 'rider@example.test', 'password' => 'password-1234'])->assertStatus(422); // rider password
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => 'rider@example.test', 'password' => 'a-separate-staff-password'])->assertOk();

        // Inviters cannot invite into more than they hold.
        $inviter = $this->withRole($this->role('Recruiter', ['users.invite']));
        $this->as($inviter, true)->postJson('/api/v1/admin/staff-invitations', ['email' => 'x@example.test', 'roles' => ['moderator']])->assertStatus(422);
    }

    public function test_deactivation_password_reset_and_session_revocation(): void
    {
        Notification::fake();
        $admin = $this->staff('admin');
        $rider = $this->rider(['email' => 'rider2@example.test']);
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $rider->id, 'payload' => '', 'last_activity' => time()]);

        $this->as($admin)->postJson("/api/v1/admin/users/{$rider->id}/activation", ['active' => false])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($admin)->postJson("/api/v1/admin/users/{$rider->id}/activation", ['active' => false, 'reason' => 'Repeated spam after warnings.'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $rider->id]);
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => 'rider2@example.test', 'password' => 'password-1234'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account is deactivated. Contact My Ride Nepal if you think this is a mistake.');
        $this->as($rider->fresh())->postJson('/api/v1/contributions', [])->assertForbidden();
        $this->as($admin)->postJson("/api/v1/admin/users/{$rider->id}/activation", ['active' => true])->assertOk();
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => 'rider2@example.test', 'password' => 'password-1234'])->assertOk();

        $this->as($admin)->postJson("/api/v1/admin/users/{$rider->id}/password-reset")->assertStatus(202);
        Notification::assertSentTo($rider, ResetPassword::class);
        DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $rider->id, 'payload' => '', 'last_activity' => time()]);
        $this->as($admin)->deleteJson("/api/v1/admin/users/{$rider->id}/sessions")->assertOk()->assertJsonPath('revoked', 1);
        foreach (['user.deactivated', 'user.reactivated', 'user.password_reset_sent', 'user.sessions_revoked'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_id' => $rider->id]);
        }

        // Staff accounts: not on yourself, not without users.manage_staff, never the last active administrator.
        $this->as($admin)->postJson("/api/v1/admin/staff/{$admin->id}/activation", ['active' => false, 'reason' => 'Trying it on myself.'])->assertStatus(422);
        $mod = $this->staff('moderator');
        $limited = $this->withRole($this->role('Account desk', ['users.activate', 'users.password_reset', 'users.sessions.revoke']));
        $this->as($limited)->postJson("/api/v1/admin/staff/{$mod->id}/activation", ['active' => false, 'reason' => 'Should not be allowed.'])->assertForbidden();
        $this->as($limited)->deleteJson("/api/v1/admin/staff/{$mod->id}/sessions")->assertForbidden();
        $this->as($limited)->postJson("/api/v1/admin/users/{$rider->id}/password-reset")->assertStatus(202);
        $other = $this->staff('admin');
        DB::table('staff_sessions')->insert(['id' => 'staff-sess', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        $this->as($other)->postJson("/api/v1/admin/staff/{$admin->id}/activation", ['active' => false, 'reason' => 'Leaving the team now.'])->assertOk();
        $this->assertDatabaseMissing('staff_sessions', ['id' => 'staff-sess']);
        $this->as($admin->fresh())->getJson('/api/v1/admin/overview')->assertForbidden(); // deactivated: no staff rights
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => self::STAFF_PASSWORD])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This staff account is deactivated.');
        $this->as($other)->postJson("/api/v1/admin/staff/{$mod->id}/password-reset")->assertStatus(202);
        Notification::assertSentTo($mod, StaffPasswordReset::class, fn ($n) => str_starts_with($n->toMail($mod)->actionUrl, config('mrn.admin_url').'/reset-password?'));
        DB::table('staff_sessions')->insert(['id' => 'mod-sess', 'user_id' => $mod->id, 'payload' => '', 'last_activity' => time()]);
        $this->as($other)->deleteJson("/api/v1/admin/staff/{$mod->id}/sessions")->assertOk()->assertJsonPath('revoked', 1);
        $third = $this->staff('admin');
        $this->as($third)->deleteJson("/api/v1/admin/staff/{$other->id}/roles/admin")->assertStatus(423);
        $this->as($third, true)->deleteJson("/api/v1/admin/staff/{$other->id}/roles/admin")->assertOk();
        $this->as($other->fresh())->postJson("/api/v1/admin/staff/{$third->id}/activation", ['active' => false, 'reason' => 'Should be refused now.'])->assertForbidden();
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/staff/{$third->id}/activation", ['active' => false, 'reason' => 'Moderators cannot.'])->assertForbidden();
        // Make $third the only active administrator: the others still hold the role but are deactivated.
        StaffAccount::whereIn('id', DB::table('staff_account_roles')->where('role', 'admin')->where('staff_account_id', '!=', $third->id)->pluck('staff_account_id'))->update(['deactivated_at' => now()]);
        $this->as($third, true)->deleteJson("/api/v1/admin/staff/{$third->id}/roles/admin")->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertTrue($third->fresh()->isAdmin(), 'the last active administrator stays');
        foreach (['staff.deactivated', 'staff.password_reset_sent', 'staff.sessions_revoked'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'actor_type' => 'staff']);
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\StaffAccount;
use App\Models\User;
use App\Notifications\StaffPasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Rider and staff authentication are separate: accounts, sign-in, sessions, password resets and provisioning. */
class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    private const FAILED = 'These credentials do not match a staff account.';

    private function fresh(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    public function test_rider_credentials_never_sign_in_as_staff(): void
    {
        $rider = $this->rider(['email' => 'rider@example.test']);
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => 'rider@example.test', 'password' => 'password-1234'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', self::FAILED);
        // Same answer as an unknown address and as a wrong staff password.
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => 'nobody@example.test', 'password' => 'password-1234'])->assertJsonPath('errors.email.0', self::FAILED);
        $staff = $this->staff('moderator');
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => 'wrong-password-123'])->assertJsonPath('errors.email.0', self::FAILED);
        $this->fresh()->getJson('/api/v1/admin/auth/session')->assertOk()->assertJsonPath('data', null);
        // A rider who is signed in on the public website is still a guest for the staff API.
        $this->fresh()->postJson('/api/v1/auth/login', ['email' => 'rider@example.test', 'password' => 'password-1234'])->assertOk();
        $this->getJson('/api/v1/admin/auth/session')->assertJsonPath('data', null);
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->assertSame(0, StaffAccount::where('email', $rider->email)->count());
    }

    public function test_staff_credentials_never_sign_in_as_a_rider(): void
    {
        $staff = $this->staff('admin');
        $this->fresh()->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertStatus(422);
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertOk()
            ->assertJsonPath('data.email', $staff->email)->assertJsonPath('data.roles', ['admin']);
        $this->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->getJson('/api/v1/auth/session')->assertJsonPath('data', null);
        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.signed_in', 'actor_type' => 'staff', 'subject_id' => $staff->id]);
    }

    public function test_rider_and_staff_sessions_use_different_cookies(): void
    {
        $rider = $this->rider();
        $staff = $this->staff('moderator');
        $riderCookie = config('session.cookie');
        $r = $this->fresh()->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'password-1234'])->assertOk();
        $r->assertCookie($riderCookie);
        $r->assertCookieMissing($riderCookie.'_staff');
        $s = $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertOk();
        $s->assertCookie($riderCookie.'_staff');
        $s->assertCookieMissing($riderCookie);
        // And back: the next rider request uses the rider cookie again.
        $this->fresh()->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'password-1234'])->assertOk()->assertCookie($riderCookie)->assertCookieMissing($riderCookie.'_staff');
    }

    public function test_registration_never_creates_staff(): void
    {
        $this->fresh()->postJson('/api/v1/auth/register', [
            'display_name' => 'Sneaky Rider', 'email' => 'sneaky@example.test', 'password' => 'long-enough-1',
            'role' => 'admin', 'roles' => ['admin'], 'is_admin' => true, 'permissions' => ['roles.manage'], 'staff' => true,
        ])->assertStatus(202);
        $user = User::where('email', 'sneaky@example.test')->sole();
        $this->assertSame(0, StaffAccount::where('email', 'sneaky@example.test')->count());
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $user->id)->count());
        $this->assertArrayNotHasKey('roles', $user->fresh()->toArray());
        // There is no staff sign-up endpoint at all.
        $this->fresh()->postJson('/api/v1/admin/auth/register', ['email' => 'x@example.test', 'password' => 'long-enough-12'])->assertNotFound();
    }

    public function test_password_reset_links_do_not_cross_audiences(): void
    {
        Notification::fake();
        $rider = $this->rider(['email' => 'both@example.test']);
        $staff = $this->staff('moderator');
        $staff->forceFill(['email' => 'both@example.test'])->save();

        $this->fresh()->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'both@example.test'])->assertStatus(202);
        Notification::assertSentTo($staff, StaffPasswordReset::class, fn ($n) => str_starts_with($n->toMail($staff)->actionUrl, config('mrn.admin_url').'/reset-password?'));
        Notification::assertNotSentTo($rider, ResetPassword::class);
        // Same answer for an address without a staff account.
        $this->fresh()->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'nobody@example.test'])->assertStatus(202);

        $staffToken = Password::broker('staff')->createToken($staff);
        $riderToken = Password::broker()->createToken($rider);
        // A rider token does not reset the staff password, and a staff token does not reset the rider password.
        $this->fresh()->postJson('/api/v1/admin/auth/reset-password', ['token' => $riderToken, 'email' => 'both@example.test', 'password' => 'new-staff-pass-2026', 'password_confirmation' => 'new-staff-pass-2026'])->assertStatus(422);
        $this->fresh()->postJson('/api/v1/auth/reset-password', ['token' => $staffToken, 'email' => 'both@example.test', 'password' => 'new-rider-pass-2026', 'password_confirmation' => 'new-rider-pass-2026'])->assertStatus(422);
        DB::table('staff_sessions')->insert(['id' => 'old-staff', 'user_id' => $staff->id, 'payload' => '', 'last_activity' => time()]);
        $this->fresh()->postJson('/api/v1/admin/auth/reset-password', ['token' => $staffToken, 'email' => 'both@example.test', 'password' => 'new-staff-pass-2026', 'password_confirmation' => 'new-staff-pass-2026'])->assertOk();
        $this->assertDatabaseMissing('staff_sessions', ['id' => 'old-staff']);
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => 'both@example.test', 'password' => 'new-staff-pass-2026'])->assertOk();
        $this->fresh()->postJson('/api/v1/auth/login', ['email' => 'both@example.test', 'password' => 'password-1234'])->assertOk(); // rider password unchanged
        $this->fresh()->postJson('/api/v1/auth/login', ['email' => 'both@example.test', 'password' => 'new-staff-pass-2026'])->assertStatus(422);
    }

    public function test_staff_sessions_have_their_own_shorter_limits_and_logout(): void
    {
        $riderMax = config('mrn.session_max_minutes'); // read before any request switches the audience
        $this->assertLessThan($riderMax, config('mrn.staff_session.max_minutes'));
        $this->assertLessThan(config('session.lifetime'), config('mrn.staff_session.idle_minutes'));
        $staff = $this->staff('moderator');
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertOk();
        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->travel(config('mrn.staff_session.max_minutes') + 1)->minutes();
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->travelBack();

        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertOk();
        $this->postJson('/api/v1/admin/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
    }

    public function test_deactivated_or_roleless_staff_cannot_sign_in_and_passwordless_accounts_never_do(): void
    {
        $staff = $this->staff('moderator');
        $staff->forceFill(['deactivated_at' => now()])->save();
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => self::STAFF_PASSWORD])->assertStatus(422)->assertJsonPath('errors.email.0', 'This staff account is deactivated.');
        $roleless = StaffAccount::forceCreate(['display_name' => 'No role', 'email' => 'norole@example.test', 'password' => self::STAFF_PASSWORD]);
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => $roleless->email, 'password' => self::STAFF_PASSWORD])->assertStatus(422)->assertJsonPath('errors.email.0', 'This staff account has no role. Ask an administrator.');
        // Carried-over staff accounts have no password until they set one: nothing signs them in.
        $carried = StaffAccount::forceCreate(['display_name' => 'Carried over', 'email' => 'carried@example.test']);
        DB::table('staff_account_roles')->insert(['staff_account_id' => $carried->id, 'role' => 'admin', 'granted_at' => now()]);
        foreach (['', 'password', self::STAFF_PASSWORD] as $pw) {
            $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => 'carried@example.test', 'password' => $pw])->assertStatus(422);
        }
    }

    public function test_rider_account_linking_is_explicit_and_proven_with_the_rider_password(): void
    {
        $rider = $this->rider(['email' => 'me.rider@example.test']);
        $staff = $this->staff('moderator');
        $this->fresh()->actingAs($staff)->postJson('/api/v1/admin/auth/rider-link', ['email' => 'me.rider@example.test', 'password' => 'not-the-password'])->assertStatus(422);
        $this->assertNull($staff->fresh()->rider_user_id);
        $this->actingAs($staff)->postJson('/api/v1/admin/auth/rider-link', ['email' => 'me.rider@example.test', 'password' => 'password-1234'])->assertOk()->assertJsonPath('data.rider_linked', true);
        $this->assertSame($rider->id, $staff->fresh()->rider_user_id);
        // One rider account, one staff account.
        $this->actingAs($this->staff('moderator'))->postJson('/api/v1/admin/auth/rider-link', ['email' => 'me.rider@example.test', 'password' => 'password-1234'])->assertStatus(422);
        // Linking grants the rider nothing.
        $this->fresh()->actingAs($rider)->getJson('/api/v1/admin/overview')->assertUnauthorized();
        $this->fresh()->actingAs($staff)->deleteJson('/api/v1/admin/auth/rider-link')->assertOk()->assertJsonPath('data.rider_linked', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.rider_linked']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.rider_unlinked']);
    }

    public function test_the_setup_command_creates_only_the_first_administrator_or_completes_a_carried_over_one(): void
    {
        $this->artisan('mrn:staff:create-admin', ['email' => 'First@Example.test', '--name' => 'First Admin'])
            ->expectsQuestion('Staff password (at least 12 characters)', 'first-admin-pass')
            ->expectsQuestion('Repeat the password', 'first-admin-pass')
            ->assertSuccessful();
        $first = StaffAccount::where('email', 'first@example.test')->sole();
        $this->assertSame(['admin'], $first->staffRoles());
        // Now an administrator can sign in: the command refuses new accounts.
        $this->artisan('mrn:staff:create-admin', ['email' => 'second@example.test', '--name' => 'Second'])->assertFailed();
        $this->assertFalse(StaffAccount::where('email', 'second@example.test')->exists());
        // ... but still completes a carried-over administrator that has no password.
        $carried = StaffAccount::forceCreate(['display_name' => 'Carried Admin', 'email' => 'carried.admin@example.test']);
        DB::table('staff_account_roles')->insert(['staff_account_id' => $carried->id, 'role' => 'admin', 'granted_at' => now()]);
        $this->artisan('mrn:staff:create-admin', ['email' => 'carried.admin@example.test'])
            ->expectsQuestion('Staff password (at least 12 characters)', 'carried-admin-pass')
            ->expectsQuestion('Repeat the password', 'carried-admin-pass')
            ->assertSuccessful();
        $this->fresh()->postJson('/api/v1/admin/auth/login', ['email' => 'carried.admin@example.test', 'password' => 'carried-admin-pass'])->assertOk();
        // Short or mismatched passwords are refused.
        $this->artisan('mrn:staff:create-admin', ['email' => 'carried.admin@example.test'])->assertFailed(); // has a password now
    }
}

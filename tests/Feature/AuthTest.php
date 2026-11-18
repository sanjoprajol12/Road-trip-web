<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function verifyUrl(User $u, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('api.auth.verify-email', now()->addMinutes($minutes), ['id' => $u->id, 'hash' => sha1($u->email)]);
    }

    public function test_registration_sends_a_confirmation_email_and_creates_no_session(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', ['display_name' => 'New Rider', 'email' => 'New@Example.test', 'password' => 'long-enough-1'])
            ->assertStatus(202)->assertJson(['message' => 'Check your email for a confirmation link.']);
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertStringStartsWith('$argon2id$', $user->password);
        Notification::assertSentTo($user, VerifyEmailAddress::class);
        $this->getJson('/api/v1/users/me')->assertStatus(401);
    }

    public function test_duplicate_registration_answers_the_same_and_creates_nothing(): void
    {
        Notification::fake();
        $existing = $this->rider(['email' => 'taken@example.test']);
        $this->postJson('/api/v1/auth/register', ['display_name' => 'Someone', 'email' => 'TAKEN@example.test', 'password' => 'long-enough-1'])
            ->assertStatus(202)->assertJson(['message' => 'Check your email for a confirmation link.']);
        $this->assertSame(1, User::where('email', 'taken@example.test')->count());
        Notification::assertNothingSent();
        $this->assertTrue(Hash::check('password-1234', $existing->fresh()->password), 'existing password untouched');
    }

    public function test_registration_validates_input(): void
    {
        $this->postJson('/api/v1/auth/register', ['display_name' => 'x', 'email' => 'not-an-email', 'password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['display_name', 'email', 'password']);
    }

    public function test_email_link_confirms_and_signs_in(): void
    {
        $user = $this->rider(['email_verified_at' => null]);
        $this->get($this->verifyUrl($user))->assertRedirect('/account?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_email_link_works_when_opened_straight_from_an_email_client(): void
    {
        $user = $this->rider(['email_verified_at' => null]);
        $this->flushHeaders(); // no Origin/Referer: not a request from the SPA
        $this->get($this->verifyUrl($user))->assertRedirect('/account?verified=1');
        $this->assertAuthenticatedAs($user);
    }

    public function test_tampered_or_expired_email_links_are_refused(): void
    {
        $user = $this->rider(['email_verified_at' => null]);
        $this->get(str_replace(sha1($user->email), sha1('other@x.test'), $this->verifyUrl($user)))->assertRedirect('/sign-in?verification=invalid');
        $this->get($this->verifyUrl($user).'x')->assertRedirect('/sign-in?verification=invalid');
        $expired = $this->verifyUrl($user, -5);
        $this->get($expired)->assertRedirect('/sign-in?verification=invalid');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertGuest();
    }

    public function test_unconfirmed_account_cannot_sign_in_but_only_learns_it_with_the_right_password(): void
    {
        $user = $this->rider(['email_verified_at' => null]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-1234'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Confirm your email first: open the link we sent when you signed up.');
        $this->assertGuest();
    }

    public function test_login_logout_and_unknown_email(): void
    {
        $user = $this->rider();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'password-1234'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => 'password-1234'])->assertOk();
        $this->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.password');
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/users/me')->assertStatus(401);
    }

    public function test_login_attempts_are_limited(): void
    {
        $user = $this->rider();
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope-nope'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-1234'])->assertStatus(429);
    }

    public function test_suspended_rider_cannot_sign_in(): void
    {
        $user = $this->rider(['suspended_until' => now()->addDay()]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-1234'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => str_starts_with($m, 'This account is suspended until'));
    }

    public function test_password_reset_flow_and_generic_answers(): void
    {
        Notification::fake();
        $user = $this->rider();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->json('message');
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $this->assertSame($unknown, $known, 'same answer for known and unknown addresses');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token, $user) {
            $token = $n->token;
            $this->assertStringContainsString('/reset-password?token=', $n->toMail($user)->actionUrl);

            return true;
        });
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(), 'old sessions ended');
        // A used token does not work twice.
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'another-pass-1'])->assertStatus(422);
    }

    public function test_expired_reset_token_is_refused(): void
    {
        $user = $this->rider();
        $token = Password::broker()->createToken($user);
        $this->travel(31)->minutes();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass'])->assertStatus(422);
        $this->assertTrue(Hash::check('password-1234', $user->fresh()->password));
    }

    public function test_password_change_needs_the_current_password_and_ends_other_sessions(): void
    {
        $user = $this->rider();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'brand-new-pass'])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/v1/auth/password', ['current_password' => 'password-1234', 'password' => 'brand-new-pass'])->assertOk();
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertFalse(DB::table('sessions')->where('id', 'other-device')->exists());
    }

    public function test_session_expires_after_the_absolute_lifetime(): void
    {
        $user = $this->rider();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password-1234'])->assertOk();
        $this->getJson('/api/v1/users/me')->assertOk();
        $this->travel(config('mrn.session_max_minutes') + 1)->minutes();
        $this->getJson('/api/v1/users/me')->assertStatus(401);
    }

    public function test_revoking_sessions_signs_out_other_devices(): void
    {
        $user = $this->rider();
        DB::table('sessions')->insert(['id' => 'phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->deleteJson('/api/v1/users/me/sessions/others')->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', 'phone')->exists());
    }

    /** Supabase Auth (GoTrue) stores bcrypt "$2a$10$" hashes. Verified against a real GoTrue hash on 2026-11-18. */
    public function test_imported_supabase_bcrypt_hash_signs_in_and_is_rehashed_to_argon2id(): void
    {
        $gotrueStyle = '$2a$'.substr(password_hash('Imported-pass-1', PASSWORD_BCRYPT, ['cost' => 10]), 4);
        $user = $this->rider();
        DB::table('users')->where('id', $user->id)->update(['password' => $gotrueStyle]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-pass'])->assertStatus(422);
        $this->assertSame($gotrueStyle, $user->fresh()->password, 'a failed attempt changes nothing');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Imported-pass-1'])->assertOk();
        $this->assertStringStartsWith('$argon2id$', $user->fresh()->password);
        $this->assertTrue(Hash::check('Imported-pass-1', $user->fresh()->password));
    }

    public function test_session_endpoint_answers_guests_and_riders_without_errors(): void
    {
        $this->getJson('/api/v1/auth/session')->assertOk()->assertJson(['data' => null]);
        $user = $this->rider();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/session')->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.password');
    }
}

<?php

namespace Tests;

use App\Enums\VerificationKind;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use App\Models\Verification;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Requests carry the frontend origin so Sanctum treats them as the SPA (stateful, cookie session). */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://127.0.0.1:5180', 'Referer' => 'http://127.0.0.1:5180/', 'Accept' => 'application/json']);
    }

    /**
     * Tests share one in-memory session store, while real browsers hold separate rider and staff sessions (cookie
     * and table: SelectSessionAudience). Mirror that: a staff request never carries the rider guard's session keys.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (str_contains($uri, '/api/v1/admin')) {
            $this->app['session']->driver()->forget('password_hash_web');
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function rider(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    /** Password of every staff account made by staffAccount(). */
    protected const STAFF_PASSWORD = 'staff-password-2026';

    /**
     * A staff account (its own account, never a rider) with these roles. Pass $linkedRider to model a staff member
     * who also rides and has linked that rider account (the explicit linking flow).
     */
    protected function staff(string $role, ?User $linkedRider = null): StaffAccount
    {
        $s = new StaffAccount(['display_name' => 'Staff '.fake()->firstName(), 'email' => 'staff-'.fake()->unique()->userName().'@example.test', 'password' => self::STAFF_PASSWORD]);
        $s->forceFill(['rider_user_id' => $linkedRider?->id])->save();
        DB::table('staff_account_roles')->insert(['staff_account_id' => $s->id, 'role' => $role, 'granted_at' => now()]);

        return $s;
    }

    /**
     * Staff accounts always authenticate on the staff guard and riders on the rider session guard (`web`, which
     * Sanctum's stateful API reads), whatever guard a test names: the same guards a browser session uses.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        // Acting as someone means using their browser: it holds no session of the other audience.
        $other = $user instanceof StaffAccount ? 'web' : 'staff';
        $session = $this->app['session']->driver();
        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with($key, "login_{$other}_") || $key === "password_hash_{$other}") {
                $session->forget($key);
            }
        }
        if ($user instanceof StaffAccount) {
            return parent::actingAs($user, 'staff');
        }
        $this->app['auth']->guard('web')->setUser($user); // public routes read the session guard

        return parent::actingAs($user, 'sanctum'); // protected routes read Sanctum (which caches its user)
    }

    protected function verified(User $user, VerificationKind $kind, string $scope = ''): Verification
    {
        $v = $user->verifications()->create(['kind' => $kind->value, 'scope' => $scope, 'statement' => 'Test statement long enough to pass the check.']);
        $v->forceFill(['status' => 'approved'])->save();

        return $v;
    }

    protected function organizer(): User
    {
        $u = $this->rider();
        $this->verified($u, VerificationKind::EventOrganizer);

        return $u;
    }
}

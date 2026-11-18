<?php

namespace Tests;

use App\Enums\VerificationKind;
use App\Models\User;
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

    protected function rider(array $attrs = []): User
    {
        return User::factory()->create($attrs);
    }

    protected function staff(string $role): User
    {
        $u = $this->rider();
        DB::table('user_roles')->insert(['user_id' => $u->id, 'role' => $role, 'granted_at' => now()]);

        return $u;
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

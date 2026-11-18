<?php

namespace App\Actions\Users;

use App\Enums\StaffRole;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangeStaffRole
{
    public function grant(User $admin, User $target, StaffRole $role): void
    {
        DB::transaction(function () use ($admin, $target, $role) {
            $inserted = DB::table('user_roles')->insertOrIgnore(['user_id' => $target->id, 'role' => $role->value, 'granted_by' => $admin->id, 'granted_at' => now()]);
            if ($inserted) {
                Audit::record('role.granted', 'user_roles', null, ['user_id' => $target->id, 'role' => $role->value], $target->id);
                $this->endSessions($target);
            }
        });
    }

    public function revoke(User $admin, User $target, StaffRole $role): void
    {
        DB::transaction(function () use ($target, $role) {
            if ($role === StaffRole::Admin) {
                // Lock every admin row so two admins cannot remove each other at the same moment.
                $admins = DB::table('user_roles')->where('role', StaffRole::Admin->value)->lockForUpdate()->pluck('user_id');
                if ($admins->count() === 1 && $admins->first() === $target->id) {
                    throw ValidationException::withMessages(['role' => ['At least one administrator must remain.']]);
                }
            }
            $deleted = DB::table('user_roles')->where('user_id', $target->id)->where('role', $role->value)->delete();
            if (! $deleted) {
                throw ValidationException::withMessages(['role' => ['That person does not have this role.']]);
            }
            Audit::record('role.removed', 'user_roles', ['user_id' => $target->id, 'role' => $role->value], null, $target->id);
            $this->endSessions($target);
        });
    }

    /** Privilege changes take effect on the next request: the person signs in again with the new rights. */
    private function endSessions(User $target): void
    {
        if ($target->id !== auth()->id()) {
            DB::table('sessions')->where('user_id', $target->id)->delete();
        }
    }
}

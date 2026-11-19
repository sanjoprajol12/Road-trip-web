<?php

namespace App\Actions\Users;

use App\Enums\StaffRole;
use App\Models\StaffAccount;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Roles belong to staff accounts only (`staff_account_roles`). Rider accounts never hold a role. */
class ChangeStaffRole
{
    public function grant(StaffAccount $actor, StaffAccount $target, string $role): void
    {
        self::assertGrantable($actor, $role);
        DB::transaction(function () use ($actor, $target, $role) {
            $inserted = DB::table('staff_account_roles')->insertOrIgnore(['staff_account_id' => $target->id, 'role' => $role, 'granted_by' => $actor->id, 'granted_at' => now()]);
            if ($inserted) {
                Audit::record('role.granted', 'staff_account_roles', null, ['staff_account_id' => $target->id, 'role' => $role], $target->id);
                $this->endSessions($target);
            }
        });
        $target->forgetStaffRoles();
    }

    public function revoke(StaffAccount $actor, StaffAccount $target, string $role): void
    {
        self::assertGrantable($actor, $role);
        DB::transaction(function () use ($target, $role) {
            if ($role === StaffRole::Admin->value && self::isLastActiveAdmin($target)) {
                throw ValidationException::withMessages(['role' => ['At least one administrator must remain.']]);
            }
            $deleted = DB::table('staff_account_roles')->where('staff_account_id', $target->id)->where('role', $role)->delete();
            if (! $deleted) {
                throw ValidationException::withMessages(['role' => ['That person does not have this role.']]);
            }
            Audit::record('role.removed', 'staff_account_roles', ['staff_account_id' => $target->id, 'role' => $role], null, $target->id);
            $this->endSessions($target);
        });
        $target->forgetStaffRoles();
    }

    /** Locks the admin rows, so two administrators cannot remove (or deactivate) each other at the same moment. */
    public static function isLastActiveAdmin(StaffAccount $target): bool
    {
        $active = DB::table('staff_account_roles')->join('staff_accounts', 'staff_accounts.id', '=', 'staff_account_roles.staff_account_id')
            ->where('staff_account_roles.role', StaffRole::Admin->value)->whereNull('staff_accounts.deactivated_at')
            ->lockForUpdate()->pluck('staff_account_roles.staff_account_id');

        return $active->count() === 1 && $active->first() === $target->id;
    }

    /**
     * No privilege escalation: you can give or take away only a role whose permissions you hold yourself.
     * The admin role (every permission, now and later) can be given or removed only by an administrator.
     */
    public static function assertGrantable(StaffAccount $actor, string $role): void
    {
        if (! DB::table('roles')->where('slug', $role)->exists()) {
            throw ValidationException::withMessages(['role' => ['There is no such role.']]);
        }
        if ($actor->isAdmin()) {
            return;
        }
        $missing = $role === StaffRole::Admin->value ? ['every permission']
            : array_diff(DB::table('permission_role')->where('role_slug', $role)->pluck('permission_key')->all(), $actor->permissions());
        if ($missing) {
            throw ValidationException::withMessages(['role' => ['You can only give roles whose permissions you have yourself.']]);
        }
    }

    /** Privilege changes take effect at once: the person signs in again with the new rights. */
    private function endSessions(StaffAccount $target): void
    {
        if ($target->id !== auth('staff')->id()) {
            DB::table('staff_sessions')->where('user_id', $target->id)->delete();
        }
    }
}

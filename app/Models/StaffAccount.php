<?php

namespace App\Models;

use App\Enums\StaffRole;
use App\Notifications\StaffPasswordReset;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * A staff member's own account (guard `staff`, table `staff_accounts`). Completely separate from rider accounts:
 * its own password, session cookie, reset links and provisioning (invitation or `mrn:staff:create-admin`).
 * A rider account never becomes staff, and a staff account never acts as a rider.
 */
class StaffAccount extends Authenticatable
{
    use HasUuids, Notifiable;

    protected $fillable = ['display_name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    private ?array $rolesCache = null;

    private ?array $permissionsCache = null;

    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return list<string> */
    public function staffRoles(): array
    {
        return $this->rolesCache ??= DB::table('staff_account_roles')->where('staff_account_id', $this->id)->orderBy('role')->pluck('role')->all();
    }

    public function forgetStaffRoles(): void
    {
        $this->rolesCache = null;
        $this->permissionsCache = null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** A deactivated account keeps its roles on record but has no rights until it is reactivated. */
    public function isActiveStaff(): bool
    {
        return $this->staffRoles() !== [] && ! $this->isDeactivated();
    }

    public function isAdmin(): bool
    {
        return in_array(StaffRole::Admin->value, $this->staffRoles(), true) && ! $this->isDeactivated();
    }

    /**
     * Permission keys from all of this account's roles, read once per request so a role change applies on the
     * next request. The admin system role holds every key in the registry, including keys added later.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if (! $this->isActiveStaff()) {
            return [];
        }
        if ($this->isAdmin()) {
            return Permissions::keys();
        }

        return $this->permissionsCache ??= DB::table('permission_role')->whereIn('role_slug', $this->staffRoles())
            ->distinct()->orderBy('permission_key')->pluck('permission_key')->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissions(), true);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_user_id');
    }

    /**
     * Whether this staff member is (or may be) the rider who owns something, for the "nobody reviews their own
     * work" rule: the explicitly linked rider account, and, conservatively, a rider account with the same email.
     * The email match only ever refuses a review; it never links accounts or grants anything.
     */
    public function isSamePersonAs(?string $riderUserId): bool
    {
        if ($riderUserId === null) {
            return false;
        }

        return $this->rider_user_id === $riderUserId
            || User::whereKey($riderUserId)->where('email', $this->email)->exists();
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new StaffPasswordReset($token));
    }
}

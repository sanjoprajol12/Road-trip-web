<?php

namespace App\Models;

use App\Enums\StaffRole;
use App\Enums\VerificationKind;
use App\Enums\VerificationStatus;
use App\Notifications\VerifyEmailAddress;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = ['display_name', 'username', 'email', 'password', 'bio', 'home_city'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null cached for the request */
    private ?array $staffRolesCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return list<string> */
    public function staffRoles(): array
    {
        return $this->staffRolesCache ??= DB::table('user_roles')->where('user_id', $this->id)->orderBy('role')->pluck('role')->all();
    }

    public function forgetStaffRoles(): void
    {
        $this->staffRolesCache = null;
    }

    public function isStaff(): bool
    {
        return $this->staffRoles() !== [];
    }

    public function isAdmin(): bool
    {
        return in_array(StaffRole::Admin->value, $this->staffRoles(), true);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /** Approved claim of this kind (and field, for experts). Read live, so suspension takes effect at once. */
    public function hasVerification(VerificationKind $kind, string $scope = ''): bool
    {
        return $this->verifications()
            ->where('kind', $kind->value)->where('scope', $scope)
            ->where('status', VerificationStatus::Approved->value)->exists();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_until !== null && $this->suspended_until->isFuture();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailAddress);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}

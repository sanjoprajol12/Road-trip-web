<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffAccountResource;
use App\Models\StaffAccount;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Staff authentication (/api/v1/admin/auth). Only `staff_accounts` are looked at: rider credentials never sign in
 * here, and there is no staff sign-up. Accounts come from invitations or `php artisan mrn:staff:create-admin`.
 */
class AuthController extends Controller
{
    private const FAILED = 'These credentials do not match a staff account.';

    // A valid Argon2id hash of a random string: unknown emails cost the same work as a wrong password.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$SmxSc2ZKYWcyWWxiZVNjSQ$J3F4FM9tTHZHNA03A3hQxp7xuZp2eV2dxVbJ3ylvh5Q';

    /** Starts the staff session and sets the XSRF-TOKEN cookie for it (the staff portal never calls /sanctum). */
    public function csrf(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /** 200 with the signed-in staff member or null, so the portal can start without a 401. */
    public function session(Request $request): JsonResponse
    {
        $staff = Auth::guard('staff')->user();

        return response()->json(['data' => $staff ? new StaffAccountResource($staff) : null]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:200']]);
        $staff = StaffAccount::where('email', Str::lower(trim($data['email'])))->first();
        if (! Hash::check($data['password'], $staff?->password ?? self::DUMMY_HASH) || ! $staff?->password) {
            throw ValidationException::withMessages(['email' => [self::FAILED]]);
        }
        // Only after the password is right, so these messages never reveal whether an account exists.
        if ($staff->isDeactivated()) {
            throw ValidationException::withMessages(['email' => ['This staff account is deactivated.']]);
        }
        if ($staff->staffRoles() === []) {
            throw ValidationException::withMessages(['email' => ['This staff account has no role. Ask an administrator.']]);
        }
        Auth::guard('staff')->login($staff);
        $request->session()->regenerate();
        $request->session()->put('auth_started_at', now()->timestamp);
        $staff->forceFill(['last_login_at' => now()])->save();
        Audit::record('staff.signed_in', $staff);

        return response()->json(['data' => new StaffAccountResource($staff)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /** Same answer whether or not the address has a staff account. The link opens the staff portal. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        Password::broker('staff')->sendResetLink(['email' => Str::lower(trim($data['email']))]);

        return response()->json(['message' => 'If that address has a staff account, a link to set a new password is on its way.'], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->max(200)],
        ]);
        $status = Password::broker('staff')->reset(
            ['email' => Str::lower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (StaffAccount $staff, string $password) {
                $staff->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('staff_sessions')->where('user_id', $staff->id)->delete();
                Audit::record('staff.password_reset', $staff);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This link is not valid any more. Ask for a new one.']]);
        }

        return response()->json(['message' => 'Your staff password is set. Sign in with it.']);
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        if (! Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['That is not your staff password.']]);
        }
        $request->session()->put('auth.password_confirmed_at', time());

        return response()->json(['message' => 'Confirmed.']);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->max(200)],
        ]);
        $staff = $request->user();
        if (! Hash::check($data['current_password'], (string) $staff->password)) {
            throw ValidationException::withMessages(['current_password' => ['That is not your current staff password.']]);
        }
        $staff->forceFill(['password' => $data['password']])->save();
        DB::table('staff_sessions')->where('user_id', $staff->id)->where('id', '!=', $request->session()->getId())->delete();
        Audit::record('staff.password_changed', $staff);

        return response()->json(['message' => 'Password changed. Other staff sessions were signed out.']);
    }

    /**
     * Accepting a staff invitation. The token proves the email address. A new staff account is created with its
     * own password; an existing staff account with a password must give that password (the token alone never
     * takes over an account). Rider accounts are never involved.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:48'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
            'display_name' => ['nullable', 'string', 'min:2', 'max:60'],
        ]);
        $email = Str::lower(trim($data['email']));

        DB::transaction(function () use ($data, $email) {
            $inv = DB::table('staff_invitations')->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $inv || $inv->email !== $email || $inv->accepted_at || $inv->revoked_at || now()->greaterThan($inv->expires_at)) {
                throw ValidationException::withMessages(['token' => ['This invitation link is not valid any more. Ask for a new one.']]);
            }
            $staff = StaffAccount::where('email', $email)->lockForUpdate()->first();
            if ($staff?->password) {
                if (! Hash::check($data['password'], $staff->password)) {
                    throw ValidationException::withMessages(['password' => ['A staff account with this email exists: enter its current staff password.']]);
                }
                if ($staff->isDeactivated()) {
                    throw ValidationException::withMessages(['email' => ['This staff account is deactivated.']]);
                }
            } else {
                validator($data, ['display_name' => ['required'], 'password' => [PasswordRule::min(8)->max(200)]],
                    ['display_name.required' => 'Enter the name other staff will see.'])->validate();
                $staff ??= new StaffAccount(['email' => $email]);
                $staff->forceFill(['display_name' => trim($data['display_name']), 'password' => $data['password']])->save();
            }
            // Roles deleted since the invitation was sent are skipped.
            $roles = DB::table('roles')->whereIn('slug', json_decode($inv->roles))->pluck('slug');
            foreach ($roles as $role) {
                DB::table('staff_account_roles')->insertOrIgnore(['staff_account_id' => $staff->id, 'role' => $role, 'granted_by' => $inv->invited_by_staff_id, 'granted_at' => now()]);
            }
            DB::table('staff_invitations')->where('id', $inv->id)->update(['accepted_at' => now(), 'staff_account_id' => $staff->id, 'updated_at' => now()]);
            DB::table('staff_sessions')->where('user_id', $staff->id)->delete();
            Audit::record('staff_invitation.accepted', 'staff_invitations', null, ['staff_account_id' => $staff->id, 'roles' => $roles->all()], $inv->id);
        });

        return response()->json(['message' => 'Invitation accepted. Sign in to the staff portal.']);
    }

    /**
     * Explicitly links this staff account to the staff member's own rider account, proven with the rider password.
     * The link only makes the "nobody reviews their own work" rule recognise that person's rider content; it never
     * signs anyone in, shares a session or grants a rider any staff right.
     */
    public function linkRider(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255'], 'password' => ['required', 'string', 'max:200']]);
        $rider = User::where('email', Str::lower(trim($data['email'])))->first();
        if (! $rider || ! FortifyServiceProvider::passwordMatches($rider, $data['password'])) {
            Hash::check($data['password'], self::DUMMY_HASH);
            throw ValidationException::withMessages(['email' => ['These rider credentials do not match.']]);
        }
        $staff = $request->user();
        if (StaffAccount::where('rider_user_id', $rider->id)->where('id', '!=', $staff->id)->exists()) {
            throw ValidationException::withMessages(['email' => ['That rider account is already linked to another staff account.']]);
        }
        $staff->forceFill(['rider_user_id' => $rider->id])->save();
        Audit::record('staff.rider_linked', $staff, null, ['rider_user_id' => $rider->id]);

        return response()->json(['data' => new StaffAccountResource($staff)]);
    }

    public function unlinkRider(Request $request): JsonResponse
    {
        $staff = $request->user();
        $old = $staff->rider_user_id;
        $staff->forceFill(['rider_user_id' => null])->save();
        Audit::record('staff.rider_unlinked', $staff, ['rider_user_id' => $old], null);

        return response()->json(['data' => new StaffAccountResource($staff)]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\ChangeStaffRole;
use App\Http\Controllers\Controller;
use App\Models\StaffAccount;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/** Staff accounts: roles, activation, password links, sessions. New staff only come in by invitation. */
class StaffAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $emails = $request->user()->hasPermission('users.emails.view');
        $like = '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%';
        $staff = StaffAccount::query()->where(fn ($w) => $w->whereLike('display_name', $like)->when($emails, fn ($w) => $w->orWhereLike('email', $like)))
            ->orderBy('display_name')->limit(200)->get();

        return response()->json(['data' => $staff->map(fn (StaffAccount $s) => [
            'id' => $s->id, 'display_name' => $s->display_name, 'email' => $emails ? $s->email : null,
            'roles' => $s->staffRoles(),
            'has_password' => $s->password !== null,
            'rider_linked' => $s->rider_user_id !== null,
            'deactivated_at' => $s->deactivated_at?->toIso8601String(),
            'last_login_at' => $s->last_login_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ])]);
    }

    public function grantRole(Request $request, StaffAccount $staff, ChangeStaffRole $roles): JsonResponse
    {
        $data = $request->validate(['role' => ['required', 'string', 'exists:roles,slug']]);
        $roles->grant($request->user(), $staff, $data['role']);

        return response()->json(['roles' => $staff->staffRoles()]);
    }

    public function revokeRole(Request $request, StaffAccount $staff, string $role, ChangeStaffRole $roles): JsonResponse
    {
        $roles->revoke($request->user(), $staff, $role);

        return response()->json(['roles' => $staff->staffRoles()]);
    }

    /** Deactivated staff cannot sign in and lose every right until reactivated; their records stay. */
    public function setActive(Request $request, StaffAccount $staff): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required_if:active,false', 'nullable', 'string', 'min:10', 'max:500']],
            ['reason.required_if' => 'Give a reason of at least 10 characters.']);
        $this->guardTarget($request->user(), $staff);
        DB::transaction(function () use ($staff, $data) {
            if (! $data['active'] && ChangeStaffRole::isLastActiveAdmin($staff)) {
                throw ValidationException::withMessages(['user' => ['At least one active administrator must remain.']]);
            }
            $old = ['deactivated_at' => $staff->deactivated_at?->toIso8601String()];
            $staff->forceFill(['deactivated_at' => $data['active'] ? null : now(), 'deactivation_reason' => $data['active'] ? null : $data['reason']])->save();
            if (! $data['active']) {
                DB::table('staff_sessions')->where('user_id', $staff->id)->delete();
            }
            Audit::record($data['active'] ? 'staff.reactivated' : 'staff.deactivated', $staff, $old, ['deactivated_at' => $staff->deactivated_at?->toIso8601String(), 'reason' => $data['reason'] ?? null]);
        });

        return response()->json(['deactivated_at' => $staff->deactivated_at?->toIso8601String()]);
    }

    /** Sends a staff password link (staff portal) to the account's own address; nobody else sees the password. */
    public function sendPasswordReset(Request $request, StaffAccount $staff): JsonResponse
    {
        $this->guardTarget($request->user(), $staff);
        Password::broker('staff')->sendResetLink(['email' => $staff->email]);
        Audit::record('staff.password_reset_sent', $staff, null, ['staff_account_id' => $staff->id]);

        return response()->json(['message' => 'A link to set a staff password was sent to the account\'s email address.'], 202);
    }

    public function revokeSessions(Request $request, StaffAccount $staff): JsonResponse
    {
        $this->guardTarget($request->user(), $staff);
        $count = DB::table('staff_sessions')->where('user_id', $staff->id)->delete();
        Audit::record('staff.sessions_revoked', $staff, null, ['sessions' => $count]);

        return response()->json(['revoked' => $count]);
    }

    private function guardTarget(StaffAccount $actor, StaffAccount $target): void
    {
        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user' => ['Use your own staff profile for this.']]);
        }
    }
}

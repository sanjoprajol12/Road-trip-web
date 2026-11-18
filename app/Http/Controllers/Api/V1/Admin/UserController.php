<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\ChangeStaffRole;
use App\Actions\Users\SuspendUser;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Staff find riders by name or username; only administrators see email addresses and can search by them. */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user()->isAdmin();
        $like = '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%';
        $users = User::query()
            ->where(fn ($w) => $w->whereLike('display_name', $like)->orWhereLike('username', $like)->when($admin, fn ($w) => $w->orWhereLike('email', $like)))
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'display_name' => $u->display_name, 'username' => $u->username,
            'email' => $admin ? $u->email : null,
            'email_verified' => $u->email_verified_at !== null,
            'roles' => $u->staffRoles(),
            'suspended_until' => $u->isSuspended() ? $u->suspended_until->toIso8601String() : null,
            'suspension_reason' => $u->isSuspended() ? $u->suspension_reason : null,
            'joined_at' => $u->created_at?->toIso8601String(),
        ])]);
    }

    public function grantRole(Request $request, User $user, ChangeStaffRole $roles): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::enum(StaffRole::class)]]);
        $roles->grant($request->user(), $user, StaffRole::from($data['role']));

        return response()->json(['roles' => $user->staffRoles()]);
    }

    public function revokeRole(Request $request, User $user, string $role, ChangeStaffRole $roles): JsonResponse
    {
        $roles->revoke($request->user(), $user, StaffRole::from($role));
        $user->forgetStaffRoles();

        return response()->json(['roles' => $user->staffRoles()]);
    }

    public function suspend(Request $request, User $user, SuspendUser $suspend): JsonResponse
    {
        $data = $request->validate(['until' => ['required', 'date', 'after:now', 'before:+5 years'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $suspend->suspend($request->user(), $user, Carbon::parse($data['until']), $data['reason']);

        return response()->json(['suspended_until' => $user->refresh()->suspended_until?->toIso8601String()]);
    }

    public function liftSuspension(User $user, SuspendUser $suspend): JsonResponse
    {
        $suspend->lift($user);

        return response()->json(null, 204);
    }
}

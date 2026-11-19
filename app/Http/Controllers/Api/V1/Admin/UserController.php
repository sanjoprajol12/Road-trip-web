<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\SuspendUser;
use App\Http\Controllers\Controller;
use App\Models\StaffAccount;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/** Rider accounts, managed by staff. Staff accounts are managed in StaffAccountController. */
class UserController extends Controller
{
    /** Staff find riders by name or username; only users.emails.view sees email addresses and can search by them. */
    public function index(Request $request): JsonResponse
    {
        $emails = $request->user()->hasPermission('users.emails.view');
        $like = '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%';
        $users = User::query()
            ->where(fn ($w) => $w->whereLike('display_name', $like)->orWhereLike('username', $like)->when($emails, fn ($w) => $w->orWhereLike('email', $like)))
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id, 'display_name' => $u->display_name, 'username' => $u->username,
            'email' => $emails ? $u->email : null,
            'email_verified' => $u->email_verified_at !== null,
            'deactivated_at' => $u->deactivated_at?->toIso8601String(),
            'suspended_until' => $u->isSuspended() ? $u->suspended_until->toIso8601String() : null,
            'suspension_reason' => $u->isSuspended() ? $u->suspension_reason : null,
            'joined_at' => $u->created_at?->toIso8601String(),
        ])]);
    }

    public function suspend(Request $request, User $user, SuspendUser $suspend): JsonResponse
    {
        $data = $request->validate(['until' => ['required', 'date', 'after:now', 'before:+5 years'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $suspend->suspend($request->user(), $user, Carbon::parse($data['until']), $data['reason']);

        return response()->json(['suspended_until' => $user->refresh()->suspended_until?->toIso8601String()]);
    }

    public function liftSuspension(Request $request, User $user, SuspendUser $suspend): JsonResponse
    {
        $this->guardTarget($request->user(), $user);
        $suspend->lift($user);

        return response()->json(null, 204);
    }

    /** Deactivated rider accounts cannot sign in; their data stays. */
    public function setActive(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required_if:active,false', 'nullable', 'string', 'min:10', 'max:500']],
            ['reason.required_if' => 'Give a reason of at least 10 characters.']);
        $this->guardTarget($request->user(), $user);
        DB::transaction(function () use ($user, $data) {
            $old = ['deactivated_at' => $user->deactivated_at?->toIso8601String()];
            $user->forceFill(['deactivated_at' => $data['active'] ? null : now()])->save();
            if (! $data['active']) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            Audit::record($data['active'] ? 'user.reactivated' : 'user.deactivated', $user, $old, ['deactivated_at' => $user->deactivated_at?->toIso8601String(), 'reason' => $data['reason'] ?? null]);
        });

        return response()->json(['deactivated_at' => $user->deactivated_at?->toIso8601String()]);
    }

    /** Sends the normal rider reset link to the account's own address; staff never see or choose the password. */
    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->guardTarget($request->user(), $user);
        Password::broker()->sendResetLink(['email' => $user->email]);
        Audit::record('user.password_reset_sent', $user, null, ['user_id' => $user->id]);

        return response()->json(['message' => 'A reset link was sent to the account\'s email address.'], 202);
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $this->guardTarget($request->user(), $user);
        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        Audit::record('user.sessions_revoked', $user, null, ['sessions' => $count]);

        return response()->json(['revoked' => $count]);
    }

    /** Staff never act on their own rider account here (linked, or with their email). */
    private function guardTarget(StaffAccount $actor, User $target): void
    {
        if ($actor->isSamePersonAs($target->id)) {
            throw ValidationException::withMessages(['user' => ['This is your own rider account: use the public website for it.']]);
        }
    }
}

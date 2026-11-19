<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Users\ChangeStaffRole;
use App\Http\Controllers\Controller;
use App\Notifications\StaffInvitationMail;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Invite new staff by email. Only a hash of the token is stored; the link is single-use and expires. */
class StaffInvitationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('staff_invitations')->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderByDesc('created_at')->get(['id', 'email', 'roles', 'expires_at', 'created_at'])
            ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'roles' => json_decode($i->roles), 'expires_at' => $i->expires_at, 'created_at' => $i->created_at])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,slug'],
        ]);
        foreach ($data['roles'] as $role) {
            ChangeStaffRole::assertGrantable($request->user(), $role); // you cannot invite someone into more than you hold
        }
        $email = Str::lower(trim($data['email']));
        $token = Str::random(48);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $email, $token, $data, $request) {
            // A new invitation replaces any open one for the same address.
            DB::table('staff_invitations')->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('staff_invitations')->insert(['id' => $id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'roles' => json_encode($data['roles']),
                'invited_by_staff_id' => $request->user()->id, 'expires_at' => now()->addHours(config('mrn.invitation_hours')), 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('staff_invitation.created', 'staff_invitations', null, ['email' => $email, 'roles' => $data['roles']], $id);
        });
        $names = DB::table('roles')->whereIn('slug', $data['roles'])->orderBy('name')->pluck('name')->all();
        Notification::route('mail', $email)->notify(new StaffInvitationMail($token, $email, $names, $request->user()->display_name));

        return response()->json(['data' => ['id' => $id, 'email' => $email]], 201);
    }

    public function destroy(string $invitation): JsonResponse
    {
        $n = DB::table('staff_invitations')->where('id', $invitation)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        abort_unless($n === 1, 404);
        Audit::record('staff_invitation.revoked', 'staff_invitations', null, null, $invitation);

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consent for group trips: the owner invites, the invitee accepts or declines within 14 days, the owner can cancel.
 * Before accepting, the invitee sees only the trip title, the inviter's display name and the role offered.
 */
class TripInvitationController extends Controller
{
    public const DAYS = 14;

    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('trip_invitations as i')->join('trips as t', 't.id', '=', 'i.trip_id')->leftJoin('users as u', 'u.id', '=', 'i.invited_by')
            ->where('i.user_id', $request->user()->id)->where('i.status', 'pending')->where('i.expires_at', '>', now())
            ->orderByDesc('i.created_at')->get(['i.id', 'i.role', 'i.expires_at', 't.title as trip_title', 'u.display_name as invited_by']);

        return response()->json(['data' => $rows]);
    }

    public function respond(Request $request, string $invitation): JsonResponse
    {
        $answer = $request->route('answer');
        abort_unless(\Illuminate\Support\Str::isUuid($invitation), 404);
        $inv = DB::table('trip_invitations')->where('id', $invitation)->where('user_id', $request->user()->id)->first();
        abort_unless($inv, 404); // someone else's invitation looks like no invitation
        if ($inv->status !== 'pending' || now()->greaterThan($inv->expires_at)) {
            throw ValidationException::withMessages(['invitation' => ['This invitation is no longer open.']]);
        }
        DB::transaction(function () use ($inv, $answer) {
            DB::table('trip_invitations')->where('id', $inv->id)->update(['status' => $answer === 'accept' ? 'accepted' : 'declined', 'responded_at' => now(), 'updated_at' => now()]);
            $trip = Trip::findOrFail($inv->trip_id);
            if ($answer === 'accept' && ! $trip->members()->where('users.id', $inv->user_id)->exists()) {
                $trip->members()->attach($inv->user_id, ['role' => $inv->role]);
            }
        });

        return response()->json(['status' => $answer === 'accept' ? 'accepted' : 'declined', 'trip_id' => $answer === 'accept' ? $inv->trip_id : null]);
    }

    public function cancel(Request $request, Trip $trip, string $invitation): JsonResponse
    {
        $this->authorize('manage', $trip);
        $n = DB::table('trip_invitations')->where(['id' => $invitation, 'trip_id' => $trip->id, 'status' => 'pending'])
            ->update(['status' => 'cancelled', 'responded_at' => now(), 'updated_at' => now()]);
        abort_unless($n === 1, 404);

        return response()->json(null, 204);
    }
}

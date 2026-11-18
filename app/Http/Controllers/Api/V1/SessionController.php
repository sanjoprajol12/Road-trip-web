<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A rider's own sign-in sessions, and "sign out everywhere else". */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->session()->getId();
        $rows = DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return response()->json(['data' => $rows->map(fn ($s) => [
            'current' => $s->id === $current,
            'ip_address' => $s->ip_address,
            'user_agent' => mb_substr((string) $s->user_agent, 0, 200),
            'last_active_at' => date(DATE_ATOM, $s->last_activity),
        ])]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $n = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();

        return response()->json(['message' => "Signed out of {$n} other ".($n === 1 ? 'session.' : 'sessions.')]);
    }
}

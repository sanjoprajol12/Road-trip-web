<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContributionResource;
use App\Http\Resources\PublicUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** One appeal per contribution pause; staff with users.suspend review it. */
    public function appealContributionSuspension(Request $request): JsonResponse
    {
        $s = \App\Models\ContributionSuspension::activeFor($request->user()->id) ?? abort(404, 'Your contribution rights are not paused.');
        abort_if($s->appealed_at, 422, 'You have already appealed. A moderator will review it.');
        $data = $request->validate(['appeal' => ['required', 'string', 'min:20', 'max:2000']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($s, $data) {
            $s->forceFill(['appeal' => trim($data['appeal']), 'appealed_at' => now()])->save();
            \App\Support\Audit::record('user.contributions_appealed', 'contribution_suspensions', null, ['user_id' => $s->user_id], $s->id);
        });

        return response()->json(['data' => $s->toRider(), 'message' => 'Appeal sent. A moderator will review it.']);
    }

    /** Riders edit only these four fields; email, roles and verification are not writable here. */
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'username' => ['nullable', 'string', 'regex:/^[a-z0-9_]{3,30}$/', Rule::unique('users')->ignore($user->id)],
            'bio' => ['nullable', 'string', 'max:500'],
            'home_city' => ['nullable', 'string', 'max:80'],
        ], ['username.regex' => '3 to 30 lowercase letters, numbers or underscores.', 'username.unique' => 'That username is taken. Try another.']);
        $user->fill(array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $data))->save();

        return new UserResource($user);
    }

    public function publicProfile(string $username): JsonResponse
    {
        $user = User::where('username', $username)->with('verifications')->firstOrFail();
        $contributions = $user->hasMany(\App\Models\Contribution::class, 'author_id')->visible()
            ->with(['destination', 'route', 'segment', 'media'])->latest()->limit(20)->get();

        return response()->json([
            'data' => PublicUserResource::full($user)->toArray(request()),
            'contributions' => ContributionResource::collection($contributions),
        ]);
    }
}

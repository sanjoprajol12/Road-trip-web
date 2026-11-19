<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Sign-up and email confirmation. The account gets no session until its email is confirmed;
 * the confirmation link signs the rider in, as the Supabase-era flow did.
 */
class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();

        return response()->json(['data' => $user ? (new \App\Http\Resources\UserResource($user))->toArray($request) : null]);
    }

    public function register(Request $request): JsonResponse
    {
        // Closing sign-up (a site setting) does not affect staff invitations or existing accounts.
        abort_unless(Settings::get('registration.open'), 403, Settings::get('registration.closed_message'));
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->max(200)],
        ]);
        $email = Str::lower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            // Same answer and similar work as a new sign-up, so this cannot be used to find accounts.
            Hash::make($data['password']);
        } else {
            DB::transaction(function () use ($data, $email) {
                $user = User::create(['display_name' => trim($data['display_name']), 'email' => $email, 'password' => $data['password']]);
                Audit::record('auth.registered', $user);
                $user->sendEmailVerificationNotification();
            });
        }

        return response()->json(['message' => 'Check your email for a confirmation link.'], 202);
    }

    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);
        if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect('/sign-in?verification=invalid');
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            Audit::record('auth.email_verified', $user);
        }
        if ($user->isSuspended()) {
            return redirect('/sign-in?verification=done');
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // no session fixation

        return redirect('/account?verified=1');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', Str::lower($request->string('email')))->first();
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If that account still needs confirming, a new link is on its way.']);
    }
}

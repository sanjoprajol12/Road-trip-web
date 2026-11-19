<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Routes are registered explicitly under /api/v1/auth (routes/api.php) with their own rate limits.
        Fortify::ignoreRoutes();

    }

    public function boot(): void
    {
        // Bound here (after Fortify registers its own) and as classes: Fortify resolves these with arguments.
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();
            if (! $user) {
                Hash::check((string) $request->input('password'), '$argon2id$v=19$m=65536,t=4,p=1$SmxSc2ZKYWcyWWxiZVNjSQ$J3F4FM9tTHZHNA03A3hQxp7xuZp2eV2dxVbJ3ylvh5Q');

                return null; // same work and same answer as a wrong password
            }
            if (! self::passwordMatches($user, (string) $request->input('password'))) {
                return null;
            }
            // Only after the password is right, so these messages never reveal whether an account exists.
            if (! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages(['email' => ['Confirm your email first: open the link we sent when you signed up.']]);
            }
            if ($user->isDeactivated()) {
                throw ValidationException::withMessages(['email' => ['This account is deactivated. Contact My Ride Nepal if you think this is a mistake.']]);
            }
            if ($user->isSuspended()) {
                throw ValidationException::withMessages(['email' => ['This account is suspended until '.$user->suspended_until->format('j M Y').'.']]);
            }

            return $user;
        });

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute((int) \App\Support\Settings::get('security.login_attempts_per_minute'))->by(Str::lower((string) $r->input('email')).'|'.$r->ip()));
    }

    /**
     * Argon2id normally. Accounts imported from Supabase Auth carry bcrypt hashes ($2a$/$2b$/$2y$):
     * verify those explicitly and re-hash to Argon2id on the spot (strict HASH_VERIFY stays on).
     */
    public static function passwordMatches(User $user, string $plain): bool
    {
        $hash = $user->getAuthPassword();
        if (preg_match('/^\$2[aby]\$/', $hash)) {
            if (! password_verify($plain, $hash)) {
                return false;
            }
            $user->forceFill(['password' => $plain])->save(); // "hashed" cast → Argon2id

            return true;
        }
        if (! Hash::check($plain, $hash)) {
            return false;
        }
        if (Hash::needsRehash($hash)) {
            $user->forceFill(['password' => $plain])->save();
        }

        return true;
    }
}

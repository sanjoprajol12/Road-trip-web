<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The API is reached through the frontend's same-origin proxy, so every generated link
        // (email confirmation, password reset) uses the public app origin.
        URL::forceRootUrl(config('app.url'));
        ResetPassword::createUrlUsing(fn (User $user, string $token) => url('/reset-password').'?'.http_build_query(['token' => $token, 'email' => $user->email]));

        Gate::define('staff', fn (User $user) => $user->isStaff());
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        $per = fn (Request $r) => $r->user()?->id ?: $r->ip();
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($per($r)));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour((int) config('mrn.limits.register_per_hour'))->by($r->ip()));
        // Signed-in requests (password change/confirm) are limited per account, others per email and IP.
        RateLimiter::for('auth-email', fn (Request $r) => Limit::perMinutes(10, 5)->by(($r->user()?->id ?? Str::lower((string) $r->input('email'))).'|'.$r->ip()));
        RateLimiter::for('applications', fn (Request $r) => Limit::perDay(5)->by($per($r)));
        RateLimiter::for('contributions', fn (Request $r) => Limit::perDay(30)->by($per($r)));
        RateLimiter::for('reports', fn (Request $r) => Limit::perDay(30)->by($per($r)));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perHour(60)->by($per($r)));
    }
}

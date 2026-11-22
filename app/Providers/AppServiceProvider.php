<?php

namespace App\Providers;

use App\Models\StaffAccount;
use App\Models\User;
use App\Support\Permissions;
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
        \App\Support\DatabaseSecurity::guardRuntime(); // F1: no owner credentials in production web requests
        // Staff mail settings (when enabled) replace MAIL_* the first time mail is used in a request or job.
        $this->app->afterResolving('mail.manager', fn () => \App\Support\MailSettings::apply());
        // Short, stable type names in `sourceables` (not class names, which would break on a rename).
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(['destination' => \App\Models\Destination::class, 'route' => \App\Models\RideRoute::class, 'contribution' => \App\Models\Contribution::class, 'place' => \App\Models\Place::class]);
        // The API is reached through the frontend's same-origin proxy, so every generated link
        // (email confirmation, password reset) uses the public app origin.
        URL::forceRootUrl(config('app.url'));
        ResetPassword::createUrlUsing(fn (User $user, string $token) => url('/reset-password').'?'.http_build_query(['token' => $token, 'email' => $user->email]));

        // Staff rights exist only on staff accounts (guard `staff`). A rider account never passes these gates,
        // whatever claims or history it has.
        Gate::define('staff', fn ($user) => $user instanceof StaffAccount && $user->isActiveStaff());
        Gate::define('admin', fn ($user) => $user instanceof StaffAccount && $user->isAdmin());
        // One gate per registry permission: routes use `can:<key>`, policies call hasPermission().
        foreach (Permissions::keys() as $key) {
            Gate::define($key, fn ($user) => $user instanceof StaffAccount && $user->hasPermission($key));
        }

        $per = fn (Request $r) => $r->user()?->id ?: $r->ip();
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute((int) config('mrn.limits.api_per_minute'))->by($per($r)));
        RateLimiter::for('register', fn (Request $r) => Limit::perHour((int) config('mrn.limits.register_per_hour'))->by($r->ip()));
        // Signed-in requests (password change/confirm) are limited per account, others per email and IP.
        RateLimiter::for('auth-email', fn (Request $r) => Limit::perMinutes(10, 5)->by(($r->user()?->id ?? Str::lower((string) $r->input('email'))).'|'.$r->ip()));
        RateLimiter::for('staff-login', fn (Request $r) => Limit::perMinute((int) \App\Support\Settings::get('security.login_attempts_per_minute'))->by('staff|'.Str::lower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('applications', fn (Request $r) => Limit::perDay(5)->by($per($r)));
        RateLimiter::for('contributions', fn (Request $r) => Limit::perDay(30)->by($per($r)));
        // Contact form: a few messages per address per hour, and a daily cap.
        RateLimiter::for('contact', fn (Request $r) => [Limit::perHour(config('mrn.limits.contact_per_hour'))->by('contact:'.$r->ip()), Limit::perDay(4 * config('mrn.limits.contact_per_hour'))->by('contact-day:'.$r->ip())]);
        RateLimiter::for('reports', fn (Request $r) => Limit::perDay(30)->by($per($r)));
        RateLimiter::for('account-deletion', fn (Request $r) => Limit::perHour(5)->by($per($r)));
        RateLimiter::for('account-export', fn (Request $r) => Limit::perDay(5)->by($per($r)));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perHour(60)->by($per($r)));
    }
}

<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every API route must be classified here. A new endpoint that is not listed is treated as a rider write and
 * must carry the rider guard + verified + not-suspended; a new /admin endpoint must carry the staff guard,
 * can:staff and a registry permission. Rider and staff guards never mix on one route. So adding an endpoint
 * without authorization, or on the wrong audience, fails this test instead of shipping quietly.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Open to guests on purpose (published content, auth entry points, health). */
    private const PUBLIC = [
        'GET api/v1/health',
        'GET api/v1/site',
        'GET api/v1/files/{asset}',
        'GET api/v1/journeys',
        'GET api/v1/content/{type}',
        'GET api/v1/content/{type}/{slug}',
        'POST api/v1/contact',
        'GET api/v1/auth/session',
        'POST api/v1/auth/register',
        'GET api/v1/auth/verify-email/{id}/{hash}',
        'POST api/v1/auth/email/resend',
        'POST api/v1/auth/login',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/reset-password',
        // Staff sign-in entry points (staff accounts only; there is no staff sign-up).
        'GET api/v1/admin/auth/csrf-cookie',
        'GET api/v1/admin/auth/session',
        'POST api/v1/admin/auth/login',
        'POST api/v1/admin/auth/forgot-password',
        'POST api/v1/admin/auth/reset-password',
        'POST api/v1/admin/auth/invitations/accept',
        'GET api/v1/destinations',
        'GET api/v1/destinations/{slug}',
        'GET api/v1/routes',
        'GET api/v1/routes/{slug}',
        'GET api/v1/contributions',
        'GET api/v1/contributions/{contribution}',
        'GET api/v1/media/{media}',
        'GET api/v1/events',
        'GET api/v1/events/{event}',
        'GET api/v1/guides',
        'GET api/v1/guides/{slug}',
        'GET api/v1/riders/{username}',
        'GET api/v1/home',
        'GET api/v1/menus/{location}',
        'GET api/v1/notices/active',
    ];

    /** Signed in is enough: managing one's own account works before email confirmation and while suspended. */
    private const ACCOUNT = [
        'POST api/v1/auth/logout',
        'PUT api/v1/auth/password',
        'POST api/v1/auth/confirm-password',
        'GET api/v1/auth/confirmed-password-status',
        'GET api/v1/users/me',
        'PATCH api/v1/users/me',
        'GET api/v1/users/me/sessions',
        'DELETE api/v1/users/me/sessions/others',
        'GET api/v1/users/me/verifications',
        'GET api/v1/users/me/contributions',
        'GET api/v1/users/me/events',
        'GET api/v1/users/me/notifications',
        'POST api/v1/users/me/notifications/read',
        'POST api/v1/users/me/contribution-appeal',
        'GET api/v1/users/me/saved',
        'POST api/v1/users/me/saved',
        'DELETE api/v1/users/me/saved/{type}/{id}',
        'GET api/v1/users/me/emergency-contacts',
        'POST api/v1/users/me/emergency-contacts',
        'PUT api/v1/users/me/emergency-contacts/{contact}',
        'PATCH api/v1/users/me/emergency-contacts/{contact}',
        'DELETE api/v1/users/me/emergency-contacts/{contact}',
    ];

    /** A signed-in staff account, with or without a role (so it can always sign out or fix its own password). */
    private const STAFF_ACCOUNT = [
        'GET api/v1/admin/cms/types',
        'POST api/v1/admin/auth/logout',
        'POST api/v1/admin/auth/confirm-password',
        'PUT api/v1/admin/auth/password',
        'POST api/v1/admin/auth/rider-link',
        'DELETE api/v1/admin/auth/rider-link',
    ];

    /** Staff routes that change privileges: they also need a fresh password confirmation. */
    private const PASSWORD_CONFIRM = [
        'POST api/v1/admin/staff/{staff}/roles',
        'DELETE api/v1/admin/staff/{staff}/roles/{role}',
        'POST api/v1/admin/roles',
        'PATCH api/v1/admin/roles/{role}',
        'DELETE api/v1/admin/roles/{role}',
        'POST api/v1/admin/staff-invitations',
    ];

    /** @return array<string, Route> "METHOD uri" => route, for every /api/v1 route */
    private function apiRoutes(): array
    {
        $out = [];
        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out["{$method} {$route->uri()}"] = $route;
            }
        }

        return $out;
    }

    public function test_every_api_route_carries_the_middleware_for_its_class(): void
    {
        $routes = $this->apiRoutes();
        $this->assertGreaterThan(90, count($routes), 'route discovery failed');

        foreach ($routes as $key => $route) {
            $mw = $route->gatherMiddleware();
            $uri = $route->uri();
            if (in_array($key, self::PUBLIC, true)) {
                continue;
            }
            if (str_starts_with($uri, 'api/v1/admin/')) {
                $this->assertContains('auth:staff', $mw, "{$key} is a staff route without auth:staff.");
                $this->assertNotContains('auth:sanctum', $mw, "{$key} is a staff route that accepts the rider guard.");
                if (in_array($key, self::STAFF_ACCOUNT, true)) {
                    continue;
                }
                $this->assertContains('can:staff', $mw, "{$key} is a staff route without can:staff.");
                // Every staff route names a permission from the registry; a bare can:staff is not enough.
                $perms = array_filter($mw, fn ($m) => str_starts_with($m, 'can:') && in_array(substr($m, 4), Permissions::keys(), true));
                $this->assertNotEmpty($perms, "{$key} has no registry permission (can:<key> from config/permissions.php).");
                $this->assertSame(in_array($key, self::PASSWORD_CONFIRM, true), in_array('password.confirm', $mw, true), "{$key}: password.confirm does not match PASSWORD_CONFIRM.");

                continue;
            }
            $this->assertContains('auth:sanctum', $mw, "{$key} is not public but has no auth:sanctum. Add the middleware or list it in PUBLIC.");
            $this->assertNotContains('auth:staff', $mw, "{$key} is a rider route that accepts the staff guard.");
            if (in_array($key, self::ACCOUNT, true)) {
                continue;
            }
            $this->assertContains('verified', $mw, "{$key} is a rider write/read without verified. Add the middleware or list it in ACCOUNT.");
            $this->assertContains('not-suspended', $mw, "{$key} lets suspended riders through.");
        }

        // The lists must not keep entries for routes that no longer exist (they would hide a renamed route).
        foreach ([...self::PUBLIC, ...self::ACCOUNT, ...self::STAFF_ACCOUNT, ...self::PASSWORD_CONFIRM] as $listed) {
            $this->assertArrayHasKey($listed, $routes, "{$listed} is listed but no such route exists.");
        }
    }

    private function url(Route $route): string
    {
        // Placeholder values that satisfy the route's own constraints; the guard must refuse before any lookup.
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => match ($m[1]) {
            'part' => 'stops',
            'role' => 'admin',
            'group' => 'general',
            'location' => 'header',
            default => (string) Str::uuid(),
        }, $route->uri());
    }

    public function test_guests_are_refused_on_every_protected_route(): void
    {
        foreach ($this->apiRoutes() as $key => $route) {
            if (in_array($key, self::PUBLIC, true)) {
                continue;
            }
            [$method] = explode(' ', $key);
            $this->app['auth']->forgetGuards();
            $status = $this->json($method, $this->url($route))->status();
            $this->assertSame(401, $status, "{$key} answered {$status} to a guest.");
        }
    }

    /** A rider session is never a staff session: every protected staff route answers 401, before any lookup. */
    public function test_riders_are_refused_on_every_staff_route(): void
    {
        $rider = $this->rider();
        $checked = 0;
        foreach ($this->apiRoutes() as $key => $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/') || in_array($key, self::PUBLIC, true)) {
                continue;
            }
            [$method] = explode(' ', $key);
            $this->app['auth']->forgetGuards();
            $status = $this->actingAs($rider)->json($method, $this->url($route))->status();
            $this->assertSame(401, $status, "{$key} answered {$status} to a rider.");
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);
    }

    /** A staff session is never a rider session: every protected rider route answers 401. */
    public function test_staff_are_refused_on_every_rider_route(): void
    {
        $admin = $this->staff('admin');
        $checked = 0;
        foreach ($this->apiRoutes() as $key => $route) {
            if (str_starts_with($route->uri(), 'api/v1/admin/') || in_array($key, self::PUBLIC, true)) {
                continue;
            }
            [$method] = explode(' ', $key);
            $this->app['auth']->forgetGuards();
            $status = $this->actingAs($admin)->json($method, $this->url($route))->status();
            $this->assertSame(401, $status, "{$key} answered {$status} to a staff account.");
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }
}

<?php

use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;

// Staff API (/api/v1/admin). Staff accounts only (guard `staff`, own session cookie and table: see
// SelectSessionAudience); a rider session never authenticates here. There is no staff sign-up.
Route::prefix('admin/auth')->name('admin.auth.')->group(function () {
    Route::get('csrf-cookie', [Admin\AuthController::class, 'csrf'])->name('csrf');
    Route::get('session', [Admin\AuthController::class, 'session'])->name('session');
    Route::post('login', [Admin\AuthController::class, 'login'])->middleware('throttle:staff-login')->name('login');
    Route::post('forgot-password', [Admin\AuthController::class, 'forgotPassword'])->middleware('throttle:auth-email')->name('forgot-password');
    Route::post('reset-password', [Admin\AuthController::class, 'resetPassword'])->middleware('throttle:auth-email')->name('reset-password');
    Route::post('invitations/accept', [Admin\AuthController::class, 'acceptInvitation'])->middleware('throttle:auth-email')->name('invitations.accept');
    // Signed in, with or without a role (so a staff member whose roles were removed can still sign out).
    Route::middleware('auth:staff')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'logout'])->name('logout');
        Route::post('confirm-password', [Admin\AuthController::class, 'confirmPassword'])->middleware('throttle:auth-email')->name('confirm-password');
        Route::put('password', [Admin\AuthController::class, 'updatePassword'])->middleware('throttle:auth-email')->name('password');
        Route::post('rider-link', [Admin\AuthController::class, 'linkRider'])->middleware('throttle:staff-login')->name('rider-link');
        Route::delete('rider-link', [Admin\AuthController::class, 'unlinkRider'])->name('rider-unlink');
    });
});

// Any active staff role reaches this group; each route then needs its own permission (config/permissions.php).
// RouteAuthorizationTest fails if a staff route has no registry permission.
Route::prefix('admin')->name('admin.')->middleware(['auth:staff', 'can:staff'])->group(function () {
    Route::get('overview', Admin\OverviewController::class)->middleware('can:overview.view');
    // A 30-minute signed token to look at the public website through maintenance mode (and, later, at drafts).
    Route::post('preview-token', function (\Illuminate\Http\Request $r) {
        $token = \App\Support\PreviewToken::issue($r->user()->id);

        return response()->json(['token' => $token, 'url' => rtrim((string) config('app.url'), '/').'/?preview='.$token, 'expires_in' => 1800]);
    })
        ->middleware(['can:overview.view', 'throttle:auth-email'])->name('preview-token');

    Route::middleware('can:verification.view')->group(function () {
        Route::get('verifications', [Admin\VerificationController::class, 'index']);
        Route::get('verifications/{claim}', [Admin\VerificationController::class, 'show']);
    });
    // Which claims a reviewer may decide is checked per claim in VerificationPolicy.
    Route::post('verifications/{claim}/decision', [Admin\VerificationController::class, 'decide'])->middleware('can:verification.decide');

    Route::middleware('can:contributions.moderate')->group(function () {
        Route::get('contributions', [Admin\ContributionController::class, 'index']);
        Route::post('contributions/{contribution}/decision', [Admin\ContributionController::class, 'decide']);
    });

    Route::get('events', [Admin\EventController::class, 'index'])->middleware('can:events.view');
    Route::post('events/{event}/cancel', [Admin\EventController::class, 'cancel'])->middleware('can:events.cancel');

    Route::apiResource('destinations', Admin\DestinationController::class)->except('show')->middleware('can:content.destinations.manage');
    Route::middleware('can:content.routes.manage')->group(function () {
        Route::apiResource('routes', Admin\RouteController::class)->except('show')->parameters(['routes' => 'route']);
        Route::get('routes/{route}', [Admin\RouteController::class, 'show']);
        Route::put('routes/{route}/segments', [Admin\RouteController::class, 'replaceSegments']);
    });
    Route::apiResource('service-points', Admin\ServicePointController::class)->except('show')->parameters(['service-points' => 'point'])->middleware('can:content.services.manage');
    Route::apiResource('guides', Admin\GuideController::class)->except('show')->middleware('can:guides.manage');
    Route::post('guides/{guide}/publish', [Admin\GuideController::class, 'publish'])->middleware('can:guides.publish');

    Route::get('users', [Admin\UserController::class, 'index'])->middleware('can:users.view');
    Route::post('users/{user}/suspension', [Admin\UserController::class, 'suspend'])->middleware('can:users.suspend');
    Route::delete('users/{user}/suspension', [Admin\UserController::class, 'liftSuspension'])->middleware('can:users.suspend');
    Route::post('users/{user}/activation', [Admin\UserController::class, 'setActive'])->middleware('can:users.activate');
    Route::post('users/{user}/password-reset', [Admin\UserController::class, 'sendPasswordReset'])->middleware(['can:users.password_reset', 'throttle:auth-email']);
    Route::delete('users/{user}/sessions', [Admin\UserController::class, 'revokeSessions'])->middleware('can:users.sessions.revoke');

    // Staff accounts. Privilege changes also need a recent password confirmation.
    Route::get('staff', [Admin\StaffAccountController::class, 'index'])->middleware('can:users.view');
    Route::middleware(['can:users.roles.manage', 'password.confirm'])->group(function () {
        Route::post('staff/{staff}/roles', [Admin\StaffAccountController::class, 'grantRole']);
        Route::delete('staff/{staff}/roles/{role}', [Admin\StaffAccountController::class, 'revokeRole']);
    });
    Route::middleware('can:users.manage_staff')->group(function () {
        Route::post('staff/{staff}/activation', [Admin\StaffAccountController::class, 'setActive'])->middleware('can:users.activate');
        Route::post('staff/{staff}/password-reset', [Admin\StaffAccountController::class, 'sendPasswordReset'])->middleware(['can:users.password_reset', 'throttle:auth-email']);
        Route::delete('staff/{staff}/sessions', [Admin\StaffAccountController::class, 'revokeSessions'])->middleware('can:users.sessions.revoke');
    });
    Route::get('roles', [Admin\RoleController::class, 'index'])->middleware('can:users.view');
    Route::get('permissions', [Admin\RoleController::class, 'permissions'])->middleware('can:roles.manage');
    Route::middleware(['can:roles.manage', 'password.confirm'])->group(function () {
        Route::post('roles', [Admin\RoleController::class, 'store']);
        Route::patch('roles/{role}', [Admin\RoleController::class, 'update']);
        Route::delete('roles/{role}', [Admin\RoleController::class, 'destroy']);
    });
    Route::get('staff-invitations', [Admin\StaffInvitationController::class, 'index'])->middleware('can:users.invite');
    Route::post('staff-invitations', [Admin\StaffInvitationController::class, 'store'])->middleware(['can:users.invite', 'password.confirm', 'throttle:auth-email']);
    Route::delete('staff-invitations/{invitation}', [Admin\StaffInvitationController::class, 'destroy'])->middleware('can:users.invite');

    Route::get('audit-logs', Admin\AuditLogController::class)->middleware('can:audit.view');

    // Site settings: one route pair per group, each behind settings.<group>.manage.
    foreach (config('settings.groups') as $group) {
        Route::get("settings/{$group}", [Admin\SettingsController::class, 'show'])->defaults('group', $group)->middleware("can:settings.{$group}.manage");
        Route::put("settings/{$group}", [Admin\SettingsController::class, 'update'])->defaults('group', $group)->middleware("can:settings.{$group}.manage");
    }
    Route::post('settings/storage/test', [Admin\SettingsController::class, 'testStorage'])->middleware('can:settings.storage.manage');
    Route::post('cache/clear', [Admin\SettingsController::class, 'clearCache'])->middleware('can:system.cache.manage');

    // CMS (routes/api/staff.php): homepage drafts and publishing, menus, notices.
    Route::middleware('can:cms.home.edit')->prefix('cms/home')->group(function () {
        Route::get('sections', [Admin\Cms\HomeSectionController::class, 'index']);
        Route::post('sections', [Admin\Cms\HomeSectionController::class, 'store']);
        Route::patch('sections/{section}', [Admin\Cms\HomeSectionController::class, 'update'])->whereUuid('section');
        Route::put('order', [Admin\Cms\HomeSectionController::class, 'reorder']);
        Route::post('sections/{section}/archive', [Admin\Cms\HomeSectionController::class, 'archive'])->whereUuid('section');
        Route::post('sections/{section}/restore', [Admin\Cms\HomeSectionController::class, 'restore'])->whereUuid('section');
    });
    Route::post('cms/home/publish', [Admin\Cms\HomeSectionController::class, 'publish'])->middleware('can:cms.home.publish');
    Route::middleware('can:cms.menus.manage')->group(function () {
        Route::get('cms/menus/{location}', [Admin\Cms\MenuController::class, 'show']);
        Route::put('cms/menus/{location}', [Admin\Cms\MenuController::class, 'update']);
    });
    Route::middleware('can:cms.notices.manage')->group(function () {
        Route::get('cms/notices', [Admin\Cms\NoticeController::class, 'index']);
        Route::post('cms/notices', [Admin\Cms\NoticeController::class, 'store']);
        Route::put('cms/notices/{notice}', [Admin\Cms\NoticeController::class, 'update'])->whereUuid('notice');
        Route::delete('cms/notices/{notice}', [Admin\Cms\NoticeController::class, 'destroy'])->whereUuid('notice');
    });

    Route::middleware('can:media.manage')->group(function () {
        Route::get('media', [Admin\MediaController::class, 'index']);
        Route::post('media', [Admin\MediaController::class, 'store'])->middleware('throttle:uploads');
        Route::patch('media/{asset}', [Admin\MediaController::class, 'update']);
        Route::delete('media/{asset}', [Admin\MediaController::class, 'destroy']);
    });
});

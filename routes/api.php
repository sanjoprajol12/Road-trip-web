<?php

use App\Http\Controllers\Api\V1 as V1;
use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers as Fortify;

/*
| /api/v1. Every route checks permissions on the server: frontend guards are only for UX.
| Writes from riders also require a verified email and an account that is not suspended.
*/
Route::prefix('v1')->name('api.')->group(function () {
    Route::get('health', V1\HealthController::class)->name('health');

    // ---------------------------------------------------------------- auth (Fortify + custom sign-up)
    Route::prefix('auth')->group(function () {
        // 200 with the signed-in user or null: lets the SPA start without a 401 for every guest.
        Route::get('session', [V1\AuthController::class, 'session']);
        Route::post('register', [V1\AuthController::class, 'register'])->middleware('throttle:register');
        // Opened from an email client: no SPA headers, so it needs the session middleware itself.
        Route::get('verify-email/{id}/{hash}', [V1\AuthController::class, 'verifyEmail'])->middleware('web')->name('auth.verify-email');
        Route::post('email/resend', [V1\AuthController::class, 'resendVerification'])->middleware('throttle:auth-email');
        Route::post('login', [Fortify\AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
        Route::post('logout', [Fortify\AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');
        Route::post('forgot-password', [Fortify\PasswordResetLinkController::class, 'store'])->middleware('throttle:auth-email');
        Route::post('reset-password', [Fortify\NewPasswordController::class, 'store'])->middleware('throttle:auth-email')->name('password.update');
        Route::put('password', [Fortify\PasswordController::class, 'update'])->middleware(['auth:sanctum', 'throttle:auth-email']);
        Route::post('confirm-password', [Fortify\ConfirmablePasswordController::class, 'store'])->middleware(['auth:sanctum', 'throttle:auth-email']);
        Route::get('confirmed-password-status', [Fortify\ConfirmedPasswordStatusController::class, 'show'])->middleware('auth:sanctum');
    });

    // ---------------------------------------------------------------- public reads
    Route::get('destinations', [V1\DestinationController::class, 'index']);
    Route::get('destinations/{slug}', [V1\DestinationController::class, 'show']);
    Route::get('routes', [V1\RouteController::class, 'index']);
    Route::get('routes/{slug}', [V1\RouteController::class, 'show']);
    Route::get('contributions', [V1\ContributionController::class, 'index']);
    Route::get('contributions/{contribution}', [V1\ContributionController::class, 'show']);
    Route::get('media/{media}', [V1\ContributionController::class, 'media'])->name('media.show');
    Route::get('events', [V1\EventController::class, 'index']);
    Route::get('events/{event}', [V1\EventController::class, 'show']);
    Route::get('guides', [V1\GuideController::class, 'index']);
    Route::get('guides/{slug}', [V1\GuideController::class, 'show']);
    Route::get('riders/{username}', [V1\ProfileController::class, 'publicProfile']);

    // ---------------------------------------------------------------- signed-in riders
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('users/me', [V1\ProfileController::class, 'me']);
        Route::patch('users/me', [V1\ProfileController::class, 'update']);
        Route::get('users/me/sessions', [V1\SessionController::class, 'index']);
        Route::delete('users/me/sessions/others', [V1\SessionController::class, 'destroyOthers']);
        Route::get('users/me/verifications', [V1\VerificationController::class, 'index']);
        Route::get('users/me/contributions', [V1\ContributionController::class, 'mine']);
        Route::get('users/me/events', [V1\EventController::class, 'mine']);
        Route::get('users/me/notifications', [V1\NotificationController::class, 'index']);
        Route::post('users/me/notifications/read', [V1\NotificationController::class, 'markRead']);
        Route::apiResource('users/me/emergency-contacts', V1\EmergencyContactController::class)->except('show')->parameters(['emergency-contacts' => 'contact']);

        Route::middleware(['verified', 'not-suspended'])->group(function () {
            Route::post('users/me/verifications', [V1\VerificationController::class, 'store'])->middleware('throttle:applications');

            Route::post('contributions', [V1\ContributionController::class, 'store'])->middleware('throttle:contributions');
            Route::patch('contributions/{contribution}', [V1\ContributionController::class, 'update']);
            Route::post('contributions/{contribution}/media', [V1\ContributionController::class, 'upload'])->middleware('throttle:uploads');
            Route::post('contributions/{contribution}/reports', [V1\ContributionController::class, 'report'])->middleware('throttle:reports');

            Route::post('events', [V1\EventController::class, 'store']);
            Route::patch('events/{event}', [V1\EventController::class, 'update']);
            Route::post('events/{event}/status', [V1\EventController::class, 'changeStatus']);
            Route::post('events/{event}/join', [V1\EventRegistrationController::class, 'join']);
            Route::post('events/{event}/leave', [V1\EventRegistrationController::class, 'leave']);
            Route::get('events/{event}/registrations', [V1\EventRegistrationController::class, 'index']);
            Route::post('events/{event}/registrations/{registration}', [V1\EventRegistrationController::class, 'decide']);
            Route::post('events/{event}/invitations', [V1\EventRegistrationController::class, 'invite']);
            Route::post('events/{event}/updates', [V1\EventController::class, 'postUpdate']);

            Route::get('trips', [V1\TripController::class, 'index']);
            Route::post('trips', [V1\TripController::class, 'store']);
            Route::get('trips/{trip}', [V1\TripController::class, 'show']);
            Route::patch('trips/{trip}', [V1\TripController::class, 'update']);
            Route::delete('trips/{trip}', [V1\TripController::class, 'destroy']);
            Route::post('trips/{trip}/members', [V1\TripController::class, 'addMember']);
            Route::patch('trips/{trip}/members/{user}', [V1\TripController::class, 'updateMember']);
            Route::delete('trips/{trip}/members/{user}', [V1\TripController::class, 'removeMember']);
            Route::post('trips/{trip}/{part}', [V1\TripPartController::class, 'store'])->whereIn('part', ['stops', 'expenses', 'checklist']);
            Route::patch('trips/{trip}/{part}/{id}', [V1\TripPartController::class, 'update'])->whereIn('part', ['stops', 'expenses', 'checklist']);
            Route::delete('trips/{trip}/{part}/{id}', [V1\TripPartController::class, 'destroy'])->whereIn('part', ['stops', 'expenses', 'checklist']);

            Route::post('guides/{guide}/expert-review', [V1\GuideController::class, 'expertReview']);
        });
    });

    // ---------------------------------------------------------------- staff (moderators and administrators)
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'can:staff'])->group(function () {
        Route::get('overview', Admin\OverviewController::class);

        Route::get('verifications', [Admin\VerificationController::class, 'index']);
        Route::get('verifications/{claim}', [Admin\VerificationController::class, 'show']);
        Route::post('verifications/{claim}/decision', [Admin\VerificationController::class, 'decide']);

        Route::get('contributions', [Admin\ContributionController::class, 'index']);
        Route::post('contributions/{contribution}/decision', [Admin\ContributionController::class, 'decide']);

        Route::get('events', [Admin\EventController::class, 'index']);
        Route::post('events/{event}/cancel', [Admin\EventController::class, 'cancel']);

        Route::apiResource('destinations', Admin\DestinationController::class)->except('show');
        Route::apiResource('routes', Admin\RouteController::class)->except('show')->parameters(['routes' => 'route']);
        Route::get('routes/{route}', [Admin\RouteController::class, 'show']);
        Route::put('routes/{route}/segments', [Admin\RouteController::class, 'replaceSegments']);
        Route::apiResource('service-points', Admin\ServicePointController::class)->except('show')->parameters(['service-points' => 'point']);
        Route::apiResource('guides', Admin\GuideController::class)->except('show');
        Route::post('guides/{guide}/publish', [Admin\GuideController::class, 'publish']);

        Route::get('users', [Admin\UserController::class, 'index']);
        Route::post('users/{user}/suspension', [Admin\UserController::class, 'suspend']);
        Route::delete('users/{user}/suspension', [Admin\UserController::class, 'liftSuspension']);

        // Administrators only. Role changes also need a recent password confirmation.
        Route::middleware('can:admin')->group(function () {
            Route::post('users/{user}/roles', [Admin\UserController::class, 'grantRole'])->middleware('password.confirm');
            Route::delete('users/{user}/roles/{role}', [Admin\UserController::class, 'revokeRole'])->middleware('password.confirm');
            Route::get('audit-logs', Admin\AuditLogController::class);
        });
    });
});

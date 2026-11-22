<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers as Fortify;

// Rider API: rider authentication (Fortify + custom sign-up) and everything a signed-in rider does.
// Writes also need a confirmed email and an account that is not suspended.
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
    Route::post('users/me/contribution-appeal', [V1\ProfileController::class, 'appealContributionSuspension'])->middleware('throttle:applications');
    Route::get('users/me/places', [V1\PlaceController::class, 'mine']);
    Route::get('users/me/saved', [V1\SavedItemController::class, 'index']);
    Route::post('users/me/saved', [V1\SavedItemController::class, 'store']);
    Route::delete('users/me/saved/{type}/{id}', [V1\SavedItemController::class, 'destroy']);
    // Account deletion and export: signed in is enough (also while suspended, unconfirmed, or in the cooling-off).
    Route::get('users/me/deletion', [V1\AccountDeletionController::class, 'show']);
    Route::post('users/me/deletion', [V1\AccountDeletionController::class, 'store'])->middleware('throttle:account-deletion');
    Route::delete('users/me/deletion', [V1\AccountDeletionController::class, 'cancel'])->middleware('throttle:account-deletion');
    Route::post('users/me/export', [V1\AccountDeletionController::class, 'export'])->middleware('throttle:account-export');
    Route::post('users/me/deletion/offers', [V1\OwnershipTransferController::class, 'offer'])->middleware('throttle:reports');
    Route::delete('users/me/deletion/offers/{offer}', [V1\OwnershipTransferController::class, 'withdraw']);
    Route::get('users/me/ownership-offers', [V1\OwnershipTransferController::class, 'incoming']);
    Route::apiResource('users/me/emergency-contacts', V1\EmergencyContactController::class)->except('show')->parameters(['emergency-contacts' => 'contact']);

    Route::middleware(['verified', 'not-suspended'])->group(function () {
        Route::post('users/me/verifications', [V1\VerificationController::class, 'store'])->middleware('throttle:applications');

        Route::post('contributions', [V1\ContributionController::class, 'store'])->middleware(['throttle:contributions', 'can-contribute']);
        Route::patch('contributions/{contribution}', [V1\ContributionController::class, 'update'])->middleware('can-contribute');
        Route::post('contributions/{contribution}/media', [V1\ContributionController::class, 'upload'])->middleware(['throttle:uploads', 'can-contribute']);
        Route::post('contributions/{contribution}/reports', [V1\ContributionController::class, 'report'])->middleware(['throttle:reports', 'can-contribute']);
        Route::post('contributions/{contribution}/confirmations', [V1\ContributionController::class, 'confirm'])->middleware(['throttle:reports', 'can-contribute']);
        Route::post('places', [V1\PlaceController::class, 'store'])->middleware(['throttle:contributions', 'can-contribute']);
        Route::patch('places/{place}', [V1\PlaceController::class, 'update'])->middleware('can-contribute')->whereUuid('place');
        Route::post('places/{place}/corrections', [V1\PlaceController::class, 'correct'])->middleware(['throttle:reports', 'can-contribute'])->whereUuid('place');
        Route::post('contributions/{contribution}/correction', [V1\ContributionController::class, 'correct'])->middleware(['throttle:contributions', 'can-contribute']);

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
        Route::delete('trips/{trip}/invitations/{invitation}', [V1\TripInvitationController::class, 'cancel'])->whereUuid('invitation');
        Route::get('users/me/trip-invitations', [V1\TripInvitationController::class, 'mine']);
        Route::post('trip-invitations/{invitation}/accept', [V1\TripInvitationController::class, 'respond'])->defaults('answer', 'accept');
        Route::post('trip-invitations/{invitation}/decline', [V1\TripInvitationController::class, 'respond'])->defaults('answer', 'decline');
        Route::patch('trips/{trip}/members/{user}', [V1\TripController::class, 'updateMember']);
        Route::delete('trips/{trip}/members/{user}', [V1\TripController::class, 'removeMember']);
        Route::post('trips/{trip}/route-parts', [V1\TripItineraryController::class, 'addRoute']);
        Route::put('trips/{trip}/stops/order', [V1\TripItineraryController::class, 'reorder']);
        Route::post('trips/{trip}/{part}', [V1\TripPartController::class, 'store'])->whereIn('part', ['stops', 'expenses', 'checklist']);
        Route::patch('trips/{trip}/{part}/{id}', [V1\TripPartController::class, 'update'])->whereIn('part', ['stops', 'expenses', 'checklist']);
        Route::delete('trips/{trip}/{part}/{id}', [V1\TripPartController::class, 'destroy'])->whereIn('part', ['stops', 'expenses', 'checklist']);

        Route::post('ownership-offers/{offer}/accept', [V1\OwnershipTransferController::class, 'respond'])->defaults('answer', 'accept');
        Route::post('ownership-offers/{offer}/decline', [V1\OwnershipTransferController::class, 'respond'])->defaults('answer', 'decline');

        Route::post('guides/{guide}/expert-review', [V1\GuideController::class, 'expertReview']);
    });
});

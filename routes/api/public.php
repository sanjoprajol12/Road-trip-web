<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

// Public API: no sign-in. Published content only (each controller and policy filters drafts and private items).
// Loaded by routes/api.php inside the /api/v1 group (route names start with "api.").
Route::get('health', V1\HealthController::class)->name('health');
Route::get('site', [V1\SiteController::class, 'show']);
Route::get('files/{asset}', [V1\SiteController::class, 'file'])->name('files.show');

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

// CMS: homepage (published sections; drafts only with a staff preview token), menus, active notices.
Route::get('home', [V1\HomeController::class, 'show']);
Route::get('menus/{location}', [V1\HomeController::class, 'menu']);
Route::get('notices/active', [V1\HomeController::class, 'notices']);

<?php

use Illuminate\Support\Facades\Route;

/*
| /api/v1, split by audience: routes/api/public.php (no sign-in), routes/api/rider.php (rider auth and rider
| features), routes/api/staff.php (/admin). Every route checks permissions on the server: frontend guards are UX only.
*/
Route::prefix('v1')->name('api.')->group(function () {
    require __DIR__.'/api/public.php';
    require __DIR__.'/api/rider.php';
    require __DIR__.'/api/staff.php';
});

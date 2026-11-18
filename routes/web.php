<?php

use Illuminate\Support\Facades\Route;

// Every non-API path is the public SPA (built by `pnpm build` into public/app). Vue Router takes it from there.
Route::get('/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'The web app has not been built. Run `pnpm build` in web/.');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('path', '^(?!api/|sanctum/|up$).*$');

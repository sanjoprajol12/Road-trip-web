<?php

use Laravel\Fortify\Features;

// Fortify serves JSON only (no views). Registration and email verification are custom
// (App\Http\Controllers\Api\V1\AuthController) so an account gets no session until its email is confirmed.
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/account',
    'prefix' => 'api/v1/auth',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
    ],
    'views' => false,
    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
    ],
];

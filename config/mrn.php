<?php

// My Ride Nepal settings that are not Laravel defaults.
return [
    'limits' => [
        // Sign-ups per IP per hour. Raise only for local E2E runs (they create many accounts from 127.0.0.1).
        'register_per_hour' => (int) env('AUTH_REGISTER_PER_HOUR', 20),
    ],
    // Absolute session lifetime in minutes, on top of the idle timeout (SESSION_LIFETIME).
    'session_max_minutes' => (int) env('SESSION_MAX_MINUTES', 60 * 24 * 7),
    'uploads' => [
        'max_kb' => 8192,
        'max_edge' => 2000,
    ],
];

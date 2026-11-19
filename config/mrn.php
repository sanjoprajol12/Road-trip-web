<?php

// My Ride Nepal settings that are not Laravel defaults.
return [
    'limits' => [
        // Sign-ups per IP per hour. Raise only for local E2E runs (they create many accounts from 127.0.0.1).
        'register_per_hour' => (int) env('AUTH_REGISTER_PER_HOUR', 20),
        // All API requests per signed-in account (or per IP for guests) per minute. Raise only for local E2E,
        // where every test browser shares 127.0.0.1.
        'api_per_minute' => (int) env('API_PER_MINUTE', 120),
        'contact_per_hour' => (int) env('CONTACT_PER_HOUR', 5),
    ],
    // Origin of the staff app (links in staff invitation emails). APP_URL is the public website.
    'admin_url' => rtrim((string) env('ADMIN_URL', 'http://127.0.0.1:5181'), '/'),
    // How long a staff invitation link works.
    'invitation_hours' => 72,
    // Absolute session lifetime in minutes, on top of the idle timeout (SESSION_LIFETIME).
    'session_max_minutes' => (int) env('SESSION_MAX_MINUTES', 60 * 24 * 7),
    // Staff sessions (staff portal): own cookie and table, shorter idle and absolute limits than riders.
    'staff_session' => [
        'idle_minutes' => (int) env('STAFF_SESSION_IDLE_MINUTES', 60),
        'max_minutes' => (int) env('STAFF_SESSION_MAX_MINUTES', 60 * 12),
    ],
    'uploads' => [
        'max_kb' => 8192,
        'max_edge' => 2000,
    ],
];

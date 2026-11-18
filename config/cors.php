<?php

// The SPA normally reaches the API through a same-origin proxy (Vite in development, the web
// server in production), so CORS matters only for cross-origin setups. Exact origins only.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('APP_URL', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'X-XSRF-TOKEN', 'X-Requested-With', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];

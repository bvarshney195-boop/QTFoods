<?php

return [
    'encrypt' => (bool) env('SESSION_ENCRYPT', (string) env('APP_ENV', 'production') === 'production'),
    'cookie' => env('SESSION_COOKIE', 'qt_foods_erp_session'),
    'secure' => (bool) env('SESSION_SECURE_COOKIE', (string) env('APP_ENV', 'production') === 'production'),
    'http_only' => (bool) env('SESSION_HTTP_ONLY', true),
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => false,
];

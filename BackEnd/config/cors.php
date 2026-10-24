<?php

$production = (string) env('APP_ENV', 'production') === 'production';
$configuredOrigins = env('QT_CORS_ALLOWED_ORIGINS');
$origins = is_string($configuredOrigins)
    ? array_values(array_filter(array_map('trim', explode(',', $configuredOrigins))))
    : ($production ? [] : [(string) env('QT_FRONTEND_URL', 'http://localhost:5173')]);

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'Origin', 'X-CSRF-TOKEN', 'X-Requested-With',
        'Idempotency-Key', 'If-Match', 'X-Correlation-ID', 'X-Request-ID', 'traceparent', 'tracestate',
    ],
    'exposed_headers' => [
        'Content-Disposition', 'Retry-After', 'X-Content-SHA256', 'X-Request-ID',
        'X-Correlation-ID', 'traceparent',
    ],
    'max_age' => 600,
    'supports_credentials' => true,
];

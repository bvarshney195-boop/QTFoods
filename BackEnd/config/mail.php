<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'require_tls' => env('MAIL_REQUIRE_TLS', false),
            // Keep sign-in requests bounded when the provider or an outbound
            // SMTP port is unavailable. Symfony otherwise inherits PHP's
            // default socket timeout (commonly 60 seconds).
            'timeout' => max(1.0, min(30.0, (float) env('MAIL_TIMEOUT', 10))),
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => [
            'transport' => 'array',
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@qtfoods.local'),
        'name' => env('MAIL_FROM_NAME', 'Q & T Foods ERP'),
    ],
];

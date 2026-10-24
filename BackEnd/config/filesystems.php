<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'private' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', 'qtfoods-evidence'),
            'root' => 'finance-archive',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'http' => [
                'connect_timeout' => (float) env('AWS_CONNECT_TIMEOUT_SECONDS', 2),
                'timeout' => (float) env('AWS_REQUEST_TIMEOUT_SECONDS', 5),
            ],
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'evidence' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', 'qtfoods-evidence'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'http' => [
                'connect_timeout' => (float) env('AWS_CONNECT_TIMEOUT_SECONDS', 2),
                'timeout' => (float) env('AWS_REQUEST_TIMEOUT_SECONDS', 5),
            ],
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'evidence_test' => [
            'driver' => 'local',
            'root' => storage_path('app/private/evidence-test'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        'evidence_legacy' => [
            'driver' => 'local',
            'root' => env('QT_EVIDENCE_LEGACY_ROOT', storage_path('app/private/evidence-legacy')),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];

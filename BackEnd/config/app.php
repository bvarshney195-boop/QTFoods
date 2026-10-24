<?php

return [
    'name' => env('APP_NAME', 'Q & T FOODS ERP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    // Persist and exchange instants in UTC. Plant-local presentation uses the
    // explicit timezone stored on each plant, avoiding implicit double shifts.
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
];

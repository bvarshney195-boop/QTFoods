<?php

use Illuminate\Support\Facades\Route;

Route::get('/backend-info', fn () => response()->json([
    'service' => 'Q & T FOODS ERP + CRM Backend',
]));

Route::get('/{path?}', function () {
    $entrypoint = public_path('index.html');

    if (! is_file($entrypoint)) {
        return response()->json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'The requested route does not exist on this host.',
            ],
        ], 404, ['Cache-Control' => 'no-store']);
    }

    return response()->file($entrypoint);
})
    ->where('path', '^(?!api(?:/|$)|backend-info(?:/|$)|up(?:/|$)).*');

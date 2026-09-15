<?php

use Illuminate\Support\Facades\Route;

Route::get('/backend-info', fn () => response()->json([
    'service' => 'Q & T FOODS ERP + CRM Backend',
]));

Route::get('/{path?}', fn () => response()->file(public_path('index.html')))
    ->where('path', '^(?!api(?:/|$)|backend-info(?:/|$)|up(?:/|$)).*');

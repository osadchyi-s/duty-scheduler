<?php

return [
    'name' => env('APP_NAME', 'WFM Duty Matrix'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Kyiv'),
    'locale' => env('APP_LOCALE', 'uk'),
    'fallback_locale' => 'uk',
    'faker_locale' => 'uk_UA',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'maintenance' => [
        'driver' => 'file',
    ],
];

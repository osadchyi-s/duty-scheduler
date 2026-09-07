<?php

return [
    'driver' => env('SESSION_DRIVER', 'redis'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'connection' => 'default',
    'cookie' => env('SESSION_COOKIE', 'wfm_session'),
];

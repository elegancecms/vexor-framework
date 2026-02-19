<?php

return [
    'name'        => env('APP_NAME', 'Vexor App'),
    'env'         => env('APP_ENV', 'production'),
    'debug'       => (bool) env('APP_DEBUG', false),
    'url'         => env('APP_URL', 'http://localhost'),
    'key'         => env('APP_KEY', ''),
    'jwt_secret'  => env('JWT_SECRET', env('APP_KEY', '')),
    'timezone'    => env('APP_TIMEZONE', 'UTC'),
    'locale'      => env('APP_LOCALE', 'en'),
];

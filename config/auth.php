<?php

return [
    'model'           => \App\Models\User::class,
    'jwt_ttl'         => (int) env('JWT_TTL', 3600),         // seconds
    'refresh_ttl'     => (int) env('JWT_REFRESH_TTL', 2592000), // 30 days
    'rate_limit'      => (int) env('AUTH_RATE_LIMIT', 5),     // login attempts per minute
    'two_factor'      => (bool) env('AUTH_2FA', false),
];

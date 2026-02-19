<?php

declare(strict_types=1);

/**
 * API Routes
 * 
 * All routes here are prefixed with /api.
 * JWT and API key authentication are used (no session/CSRF).
 */

use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;

/** @var \Vexor\Core\Router\Router $router */

$router->prefix('/api')->group(function ($r) {

    // ── Public API ────────────────────────────────────────────────────────────

    $r->get('/ping', function (Request $request): Response {
        return Response::json(['pong' => true, 'ts' => time()]);
    });

    $r->post('/auth/token', 'App\Controllers\Api\AuthController@token');
    $r->post('/auth/refresh', 'App\Controllers\Api\AuthController@refresh');

    // ── Authenticated API ─────────────────────────────────────────────────────

    $r->middleware([
        'Vexor\Core\Http\Middleware\AuthMiddleware',
        'Vexor\Core\Http\Middleware\RateLimitMiddleware',
    ])->group(function ($r) {

        // Users resource
        $r->apiResource('users', 'App\Controllers\Api\UserController');

        // Profile
        $r->get('/me', 'App\Controllers\Api\UserController@me');

        // 2FA
        $r->post('/2fa/enable', 'App\Controllers\Api\TwoFactorController@enable');
        $r->post('/2fa/verify', 'App\Controllers\Api\TwoFactorController@verify');
        $r->post('/2fa/disable', 'App\Controllers\Api\TwoFactorController@disable');

        // API Keys
        $r->get('/api-keys', 'App\Controllers\Api\ApiKeyController@index');
        $r->post('/api-keys', 'App\Controllers\Api\ApiKeyController@store');
        $r->delete('/api-keys/{id}', 'App\Controllers\Api\ApiKeyController@destroy');

    });

});

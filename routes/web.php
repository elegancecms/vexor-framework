<?php

declare(strict_types=1);

/**
 * Web Routes
 * 
 * Define your web routes here.
 * These routes have CSRF protection, session middleware applied.
 * 
 * $router is available in this file automatically.
 */

use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;

/** @var \Vexor\Core\Router\Router $router */

// ── Public Routes ─────────────────────────────────────────────────────────────

$router->get('/', function (Request $request): Response {
    return Response::json([
        'framework' => 'Vexor',
        'version'   => '1.0.0',
        'php'       => PHP_VERSION,
        'status'    => 'running',
        'message'   => '⚡ Welcome to Vexor Framework',
    ]);
});

$router->get('/health', function (Request $request): Response {
    return Response::json([
        'status'    => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'uptime'    => defined('VEXOR_START') ? round(microtime(true) - VEXOR_START, 4) . 's' : null,
    ]);
});

// ── Auth Routes ───────────────────────────────────────────────────────────────

$router->post('/login', 'App\Controllers\AuthController@login');
$router->post('/logout', 'App\Controllers\AuthController@logout');
$router->post('/register', 'App\Controllers\AuthController@register');

// ── Protected Routes ──────────────────────────────────────────────────────────

$router->middleware('App\Middleware\AuthMiddleware')->group(function ($r) {
    $r->get('/dashboard', function (Request $request): Response {
        $user = $request->getAttribute('user');
        return Response::json(['message' => 'Welcome!', 'user' => $user]);
    });

    $r->get('/profile', 'App\Controllers\ProfileController@show');
    $r->put('/profile', 'App\Controllers\ProfileController@update');
});

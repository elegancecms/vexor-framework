<?php

declare(strict_types=1);

namespace App\Controllers;

use Vexor\Core\Http\Controller;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;
use Vexor\Core\Auth\AuthManager;
use Vexor\Core\Security\SecurityManager;

class AuthController extends Controller
{
    private AuthManager $auth;
    private SecurityManager $security;

    public function __construct(\Vexor\Core\Application $app)
    {
        parent::__construct($app);
        $this->auth     = $app->make(AuthManager::class);
        $this->security = $app->make(SecurityManager::class);
    }

    public function login(Request $request): Response
    {
        // Rate-limit login attempts
        if (!$this->security->rateLimit('login:' . $request->ip(), 5, 60)) {
            return $this->error('Too many login attempts. Try again in 1 minute.', 429);
        }

        $data = $this->validate($request, [
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);

        $remember = (bool) $request->input('remember', false);

        if (!$this->auth->attempt($data, $remember)) {
            return $this->error('Invalid credentials.', 401);
        }

        $user  = $this->auth->user();
        $token = $this->auth->generateJwt($user);

        return $this->success([
            'token'        => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => config('auth.jwt_ttl', 3600),
            'user'         => $user->toArray(),
        ], 'Login successful.');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return $this->success(message: 'Logged out successfully.');
    }

    public function register(Request $request): Response
    {
        $data = $this->validate($request, [
            'name'                  => 'required|min:2|max:100',
            'email'                 => 'required|email|max:255',
            'password'              => 'required|min:8|confirmed',
        ]);

        $data['password'] = $this->security->hashPassword($data['password']);

        $user  = \App\Models\User::create($data);
        $token = $this->auth->generateJwt($user);

        return $this->success([
            'token' => $token,
            'user'  => $user->toArray(),
        ], 'Registration successful.', 201);
    }
}

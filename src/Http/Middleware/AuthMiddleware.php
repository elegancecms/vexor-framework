<?php

declare(strict_types=1);

namespace Vexor\Http\Middleware;

use Closure;
use Vexor\Http\Request;
use Vexor\Http\Response;
use Vexor\Http\MiddlewareInterface;
use Vexor\Auth\AuthManager;
use Vexor\Application;

class AuthMiddleware implements MiddlewareInterface
{
    private AuthManager $auth;

    public function __construct(Application $app)
    {
        $this->auth = $app->make(AuthManager::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->userFromRequest($request);

        if (!$user) {
            if ($request->wantsJson() || $request->isAjax()) {
                return Response::json(['error' => 'Unauthorized', 'message' => 'Authentication required.'], 401);
            }
            return Response::redirect('/login');
        }

        $request->setAttribute('user', $user);
        $request->setAttribute('auth', $this->auth);

        return $next($request);
    }
}

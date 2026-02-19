<?php

declare(strict_types=1);

namespace Vexor\Core\Http\Middleware;

use Closure;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;
use Vexor\Core\Http\MiddlewareInterface;
use Vexor\Core\Auth\AuthManager;
use Vexor\Core\Application;

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

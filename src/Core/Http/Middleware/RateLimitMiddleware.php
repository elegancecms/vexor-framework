<?php

declare(strict_types=1);

namespace Vexor\Core\Http\Middleware;

use Closure;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;
use Vexor\Core\Http\MiddlewareInterface;
use Vexor\Core\Security\SecurityManager;
use Vexor\Core\Application;
use Vexor\Core\Exceptions\HttpException;

class RateLimitMiddleware implements MiddlewareInterface
{
    private SecurityManager $security;
    private int $maxRequests;
    private int $window;

    public function __construct(Application $app, int $maxRequests = 60, int $window = 60)
    {
        $this->security    = $app->make(SecurityManager::class);
        $this->maxRequests = $maxRequests;
        $this->window      = $window;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'route:' . $request->ip() . ':' . $request->uri();

        if (!$this->security->rateLimit($key, $this->maxRequests, $this->window)) {
            return Response::json([
                'error'   => 'Too Many Requests',
                'message' => "Rate limit exceeded. Max {$this->maxRequests} requests per {$this->window} seconds.",
                'retry_after' => $this->window,
            ], 429)->withHeaders([
                'X-RateLimit-Limit'  => (string) $this->maxRequests,
                'Retry-After'        => (string) $this->window,
            ]);
        }

        return $next($request);
    }
}

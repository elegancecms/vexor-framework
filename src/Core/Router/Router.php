<?php

declare(strict_types=1);

namespace Vexor\Core\Router;

use Closure;
use Vexor\Core\Application;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;
use Vexor\Core\Exceptions\HttpException;

/**
 * Vexor Router
 * 
 * High-performance router with:
 * - Named routes
 * - Route groups with prefix and middleware
 * - Parameter constraints via regex
 * - Middleware pipeline
 * - RESTful resource routing
 */
class Router
{
    private Application $app;
    private array $routes = [];
    private array $namedRoutes = [];
    private array $groupStack = [];
    private array $globalMiddleware = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    // ── HTTP Verbs ────────────────────────────────────────────────────────────

    public function get(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    public function post(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['POST'], $uri, $action);
    }

    public function put(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['PUT'], $uri, $action);
    }

    public function patch(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['DELETE'], $uri, $action);
    }

    public function options(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['OPTIONS'], $uri, $action);
    }

    public function any(string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], $uri, $action);
    }

    public function match(array $methods, string $uri, array|string|Closure $action): Route
    {
        return $this->addRoute(array_map('strtoupper', $methods), $uri, $action);
    }

    // ── Resource ──────────────────────────────────────────────────────────────

    public function resource(string $name, string $controller, array $only = []): void
    {
        $resourceRoutes = [
            'index'   => ['GET',    "/{$name}"],
            'create'  => ['GET',    "/{$name}/create"],
            'store'   => ['POST',   "/{$name}"],
            'show'    => ['GET',    "/{$name}/{id}"],
            'edit'    => ['GET',    "/{$name}/{id}/edit"],
            'update'  => ['PUT',    "/{$name}/{id}"],
            'destroy' => ['DELETE', "/{$name}/{id}"],
        ];

        if (!empty($only)) {
            $resourceRoutes = array_intersect_key($resourceRoutes, array_flip($only));
        }

        foreach ($resourceRoutes as $action => [$method, $uri]) {
            $this->match([$method], $uri, "{$controller}@{$action}")
                 ->name("{$name}.{$action}");
        }
    }

    public function apiResource(string $name, string $controller): void
    {
        $this->resource($name, $controller, ['index', 'store', 'show', 'update', 'destroy']);
    }

    // ── Groups ────────────────────────────────────────────────────────────────

    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    public function prefix(string $prefix): GroupProxy
    {
        return new GroupProxy($this, ['prefix' => $prefix]);
    }

    public function middleware(string|array $middleware): GroupProxy
    {
        return new GroupProxy($this, ['middleware' => (array) $middleware]);
    }

    // ── Global Middleware ─────────────────────────────────────────────────────

    public function pushMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function addRoute(array $methods, string $uri, array|string|Closure $action): Route
    {
        $prefix     = $this->getCurrentPrefix();
        $middleware = $this->getCurrentMiddleware();
        $uri        = $prefix . '/' . ltrim($uri, '/');
        $uri        = rtrim($uri, '/') ?: '/';

        $route = new Route($methods, $uri, $action, $middleware);
        $this->routes[] = $route;

        return $route;
    }

    private function getCurrentPrefix(): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . ltrim($group['prefix'] ?? '', '/');
        }
        return rtrim($prefix, '/');
    }

    private function getCurrentMiddleware(): array
    {
        $middleware = $this->globalMiddleware;
        foreach ($this->groupStack as $group) {
            if (!empty($group['middleware'])) {
                $middleware = array_merge($middleware, (array) $group['middleware']);
            }
        }
        return $middleware;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri    = rawurldecode($request->uri());

        foreach ($this->routes as $route) {
            if ($route->matches($method, $uri)) {
                $params = $route->extractParams($uri);

                foreach ($params as $key => $value) {
                    $request->setAttribute($key, $value);
                }

                return $this->runThroughPipeline($request, $route);
            }
        }

        // Check if URI exists with different method
        foreach ($this->routes as $route) {
            if ($route->matchesUri($uri)) {
                throw new HttpException(405, 'Method Not Allowed');
            }
        }

        throw new HttpException(404, "Route [{$method} {$uri}] not found.");
    }

    private function runThroughPipeline(Request $request, Route $route): Response
    {
        $middleware = $route->getMiddleware();
        $handler    = fn(Request $req) => $this->callRouteAction($req, $route);

        // Build middleware pipeline (onion layers)
        $pipeline = array_reduce(
            array_reverse($middleware),
            function (Closure $carry, string $middlewareClass) {
                return function (Request $req) use ($carry, $middlewareClass) {
                    $instance = $this->app->make($middlewareClass);
                    return $instance->handle($req, $carry);
                };
            },
            $handler
        );

        return $pipeline($request);
    }

    private function callRouteAction(Request $request, Route $route): Response
    {
        $action = $route->getAction();

        if ($action instanceof Closure) {
            $result = $action($request);
        } elseif (is_array($action)) {
            [$class, $method] = $action;
            $controller = $this->app->make($class);
            $result = $controller->$method($request);
        } elseif (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $controller = $this->app->make($class);
            $result = $controller->$method($request);
        } else {
            throw new \RuntimeException('Invalid route action.');
        }

        return $this->prepareResponse($result);
    }

    private function prepareResponse(mixed $result): Response
    {
        if ($result instanceof Response) return $result;
        if (is_array($result)) return Response::json($result);
        if (is_string($result)) return Response::make($result, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        return Response::json($result);
    }

    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Named route [{$name}] not found.");
        }
        $route = $this->namedRoutes[$name];
        return $route->buildUri($params);
    }

    public function registerNamed(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
    }

    public function getRoutes(): array { return $this->routes; }
}

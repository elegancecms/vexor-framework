<?php

declare(strict_types=1);

namespace Vexor\Router;

use Closure;
use Vexor\Router\Router;

/**
 * Represents a single registered route.
 */
class Route
{
    private array $methods;
    private string $uri;
    private array|string|Closure $action;
    private array $middleware;
    private ?string $name = null;
    private string $pattern;
    private array $paramNames = [];

    public function __construct(
        array $methods,
        string $uri,
        array|string|Closure $action,
        array $middleware = []
    ) {
        $this->methods    = $methods;
        $this->uri        = $uri;
        $this->action     = $action;
        $this->middleware = $middleware;
        $this->compile();
    }

    private function compile(): void
    {
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?\}/', function (array $matches) {
            $name     = $matches[1];
            $optional = isset($matches[2]) && $matches[2] === '?';
            $this->paramNames[] = $name;

            $regex = $optional ? '([^/]*)' : '([^/]+)';
            return $optional ? '(?:/' . $regex . ')?' : $regex;
        }, $this->uri);

        $this->pattern = '#^' . $pattern . '$#u';
    }

    public function matches(string $method, string $uri): bool
    {
        return in_array($method, $this->methods) && $this->matchesUri($uri);
    }

    public function matchesUri(string $uri): bool
    {
        return (bool) preg_match($this->pattern, $uri);
    }

    public function extractParams(string $uri): array
    {
        $params = [];
        if (preg_match($this->pattern, $uri, $matches)) {
            array_shift($matches);
            foreach ($this->paramNames as $i => $name) {
                $params[$name] = $matches[$i] ?? null;
            }
        }
        return $params;
    }

    public function buildUri(array $params = []): string
    {
        $uri = $this->uri;
        foreach ($params as $key => $value) {
            $uri = preg_replace('/\{' . $key . '\??\}/', (string) $value, $uri);
        }
        // Remove optional params not supplied
        $uri = preg_replace('/\/\{[^}]+\?\}/', '', $uri);
        return $uri;
    }

    public function name(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(string|array $middleware): static
    {
        $this->middleware = array_merge($this->middleware, (array) $middleware);
        return $this;
    }

    public function getAction(): array|string|Closure { return $this->action; }
    public function getMiddleware(): array { return $this->middleware; }
    public function getMethods(): array { return $this->methods; }
    public function getUri(): string { return $this->uri; }
    public function getName(): ?string { return $this->name; }
}

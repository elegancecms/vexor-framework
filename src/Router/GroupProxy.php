<?php

declare(strict_types=1);

namespace Vexor\Router;

use Closure;

/**
 * Fluent proxy for chaining route group attributes.
 * 
 * Usage:
 *   $router->prefix('/api')->middleware('auth')->group(function($r) { ... });
 */
class GroupProxy
{
    private Router $router;
    private array $attributes;

    public function __construct(Router $router, array $attributes)
    {
        $this->router     = $router;
        $this->attributes = $attributes;
    }

    public function prefix(string $prefix): static
    {
        $this->attributes['prefix'] = ($this->attributes['prefix'] ?? '') . '/' . ltrim($prefix, '/');
        return $this;
    }

    public function middleware(string|array $middleware): static
    {
        $existing = $this->attributes['middleware'] ?? [];
        $this->attributes['middleware'] = array_merge($existing, (array) $middleware);
        return $this;
    }

    public function name(string $name): static
    {
        $this->attributes['name'] = $name;
        return $this;
    }

    public function group(Closure $callback): void
    {
        $this->router->group($this->attributes, $callback);
    }
}

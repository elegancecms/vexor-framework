<?php

declare(strict_types=1);

namespace Vexor\Core\Container;

use Closure;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

/**
 * Vexor IoC Container
 * 
 * Powerful dependency injection container with:
 * - Automatic constructor injection (autowiring)
 * - Singleton and transient bindings
 * - Interface-to-implementation binding
 * - Contextual binding support
 */
class Container
{
    protected array $bindings = [];
    protected array $singletons = [];
    protected array $instances = [];
    protected array $aliases = [];

    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $concrete ??= $abstract;

        if (!$concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->getConcrete($abstract);
        $object = $this->build($concrete, $parameters);

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    protected function build(Closure|string $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Target class [{$concrete}] does not exist.", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Target [{$concrete}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);

        return $reflector->newInstanceArgs($dependencies);
    }

    protected function resolveDependencies(array $reflectionParams, array $overrides = []): array
    {
        $resolved = [];

        foreach ($reflectionParams as $param) {
            if (array_key_exists($param->getName(), $overrides)) {
                $resolved[] = $overrides[$param->getName()];
                continue;
            }

            $type = $param->getType();

            if ($type === null || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $resolved[] = $param->getDefaultValue();
                } else {
                    throw new RuntimeException(
                        "Cannot resolve primitive parameter [{$param->getName()}]."
                    );
                }
            } else {
                $resolved[] = $this->make($type->getName());
            }
        }

        return $resolved;
    }

    protected function getConcrete(string $abstract): Closure|string
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    protected function isShared(string $abstract): bool
    {
        return isset($this->instances[$abstract]) ||
               (isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared'] === true);
    }

    protected function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    protected function getClosure(string $abstract, string $concrete): Closure
    {
        return function (Container $container, array $parameters = []) use ($abstract, $concrete) {
            if ($abstract === $concrete) {
                return $container->build($concrete, $parameters);
            }
            return $container->make($concrete, $parameters);
        };
    }

    public function bound(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
    }
}

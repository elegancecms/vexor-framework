<?php

declare(strict_types=1);

namespace Vexor\Core;

use Closure;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;
use Vexor\Core\Container\Container;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;
use Vexor\Core\Router\Router;
use Vexor\Core\Security\SecurityManager;
use Vexor\Core\Exceptions\ExceptionHandler;

/**
 * Vexor Application - Core Bootstrap
 * 
 * The heart of the Vexor framework. Bootstraps all core services,
 * manages the IoC container, and handles the request lifecycle.
 */
class Application extends Container
{
    private static ?Application $instance = null;
    private string $basePath;
    private array $serviceProviders = [];
    private array $booted = [];
    private bool $hasBooted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        static::$instance = $this;

        $this->registerBaseBindings();
        $this->registerCoreProviders();
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            throw new RuntimeException('Application has not been instantiated.');
        }
        return static::$instance;
    }

    private function registerBaseBindings(): void
    {
        $this->singleton('app', fn() => $this);
        $this->singleton(Application::class, fn() => $this);
        $this->singleton('path', fn() => $this->basePath);
        $this->singleton('path.storage', fn() => $this->basePath . '/storage');
        $this->singleton('path.config', fn() => $this->basePath . '/config');
        $this->singleton('path.routes', fn() => $this->basePath . '/routes');
    }

    private function registerCoreProviders(): void
    {
        $this->singleton('request', fn() => Request::createFromGlobals());
        $this->singleton('response', fn() => new Response());
        $this->singleton('router', fn() => new Router($this));
        $this->singleton('security', fn() => new SecurityManager($this));
        $this->singleton('exceptions', fn() => new ExceptionHandler($this));
    }

    public function bootstrap(): void
    {
        if ($this->hasBooted) return;

        $this->loadConfiguration();
        $this->bootServiceProviders();
        $this->hasBooted = true;
    }

    private function loadConfiguration(): void
    {
        $configPath = $this->basePath . '/config';
        if (!is_dir($configPath)) return;

        foreach (glob($configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            $this->singleton("config.{$key}", fn() => require $file);
        }
    }

    public function config(string $key, mixed $default = null): mixed
    {
        [$file, $dotKey] = array_pad(explode('.', $key, 2), 2, null);
        
        try {
            $config = $this->make("config.{$file}");
        } catch (\Throwable) {
            return $default;
        }

        if ($dotKey === null) return $config;

        return $this->arrayGet($config, $dotKey, $default);
    }

    private function arrayGet(array $array, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }

    public function register(string $provider): void
    {
        if (isset($this->serviceProviders[$provider])) return;

        $instance = new $provider($this);
        $instance->register();
        $this->serviceProviders[$provider] = $instance;

        if ($this->hasBooted) {
            $this->bootProvider($instance);
        }
    }

    private function bootServiceProviders(): void
    {
        foreach ($this->serviceProviders as $provider) {
            $this->bootProvider($provider);
        }
    }

    private function bootProvider(object $provider): void
    {
        $class = get_class($provider);
        if (isset($this->booted[$class])) return;

        if (method_exists($provider, 'boot')) {
            $provider->boot();
        }

        $this->booted[$class] = true;
    }

    public function handle(): void
    {
        try {
            $this->bootstrap();

            /** @var Request $request */
            $request = $this->make('request');

            /** @var SecurityManager $security */
            $security = $this->make('security');
            $security->validateRequest($request);

            /** @var Router $router */
            $router = $this->make('router');

            $routesFile = $this->basePath . '/routes/web.php';
            if (file_exists($routesFile)) {
                require $routesFile;
            }

            $apiRoutesFile = $this->basePath . '/routes/api.php';
            if (file_exists($apiRoutesFile)) {
                require $apiRoutesFile;
            }

            $response = $router->dispatch($request);
            $response->send();

        } catch (\Throwable $e) {
            /** @var ExceptionHandler $handler */
            $handler = $this->make('exceptions');
            $handler->handle($e)->send();
        }
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? '/' . ltrim($path, '/') : '');
    }

    public function version(): string
    {
        return '1.0.0';
    }
}

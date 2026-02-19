<?php

declare(strict_types=1);

use Vexor\Core\Application;
use Vexor\Core\Http\{Request, Response};
use Vexor\Core\Auth\AuthManager;
use Vexor\Core\Security\SecurityManager;

if (!function_exists('app')) {
    function app(string $abstract = null, array $params = []): mixed
    {
        $instance = Application::getInstance();
        if ($abstract === null) return $instance;
        return $instance->make($abstract, $params);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Application::getInstance()->config($key, $default);
    }
}

if (!function_exists('request')) {
    function request(string $key = null, mixed $default = null): mixed
    {
        /** @var Request $request */
        $request = app('request');
        if ($key === null) return $request;
        return $request->input($key, $default);
    }
}

if (!function_exists('response')) {
    function response(mixed $content = '', int $status = 200, array $headers = []): Response
    {
        if (is_array($content)) return Response::json($content, $status, $headers);
        return Response::make((string) $content, $status, $headers);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('auth')) {
    function auth(): AuthManager
    {
        return app(AuthManager::class);
    }
}

if (!function_exists('security')) {
    function security(): SecurityManager
    {
        return app(SecurityManager::class);
    }
}

if (!function_exists('e')) {
    /**
     * HTML-escape a string (XSS safe output).
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return security()->generateCsrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return security()->csrfField();
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) return $default;

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            default            => $value,
        };
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Application::getInstance()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('logger')) {
    function logger(string $message, string $level = 'info', array $context = []): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) mkdir($logPath, 0750, true);

        $entry = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        file_put_contents($logPath . '/app.log', $entry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('now')) {
    function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }
}

if (!function_exists('str')) {
    /**
     * Tiny string utility bag.
     */
    function str(string $value): object
    {
        return new class($value) {
            public function __construct(private string $str) {}
            public function slug(): static    { return new static(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $this->str))); }
            public function camel(): static   { return new static(lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $this->str))))); }
            public function snake(): static   { return new static(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $this->str))); }
            public function title(): static   { return new static(ucwords(strtolower($this->str))); }
            public function upper(): static   { return new static(strtoupper($this->str)); }
            public function lower(): static   { return new static(strtolower($this->str)); }
            public function trim(): static    { return new static(trim($this->str)); }
            public function limit(int $n): static { return new static(mb_substr($this->str, 0, $n) . (mb_strlen($this->str) > $n ? '...' : '')); }
            public function contains(string $needle): bool { return str_contains($this->str, $needle); }
            public function startsWith(string $needle): bool { return str_starts_with($this->str, $needle); }
            public function endsWith(string $needle): bool { return str_ends_with($this->str, $needle); }
            public function __toString(): string { return $this->str; }
        };
    }
}

if (!function_exists('collect')) {
    /**
     * Minimal collection helper.
     */
    function collect(array $items = []): object
    {
        return new class($items) {
            public function __construct(private array $items) {}
            public function map(callable $fn): static    { return new static(array_map($fn, $this->items)); }
            public function filter(callable $fn): static { return new static(array_values(array_filter($this->items, $fn))); }
            public function each(callable $fn): static   { foreach ($this->items as $k => $v) $fn($v, $k); return $this; }
            public function first(): mixed               { return $this->items[0] ?? null; }
            public function last(): mixed                { return !empty($this->items) ? end($this->items) : null; }
            public function count(): int                 { return count($this->items); }
            public function pluck(string $key): static   { return new static(array_column($this->items, $key)); }
            public function unique(): static             { return new static(array_values(array_unique($this->items))); }
            public function sortBy(string $key): static  { $items = $this->items; usort($items, fn($a, $b) => $a[$key] <=> $b[$key]); return new static($items); }
            public function toArray(): array             { return $this->items; }
            public function isEmpty(): bool              { return empty($this->items); }
            public function contains(mixed $item): bool  { return in_array($item, $this->items); }
            public function sum(string $key = null): float { return array_sum($key ? array_column($this->items, $key) : $this->items); }
            public function chunk(int $size): static     { return new static(array_chunk($this->items, $size)); }
            public function toJson(): string             { return json_encode($this->items, JSON_UNESCAPED_UNICODE); }
        };
    }
}

if (!function_exists('uuid')) {
    function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new \Vexor\Core\Exceptions\HttpException($code, $message);
    }
}

if (!function_exists('abort_if')) {
    function abort_if(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) abort($code, $message);
    }
}

if (!function_exists('abort_unless')) {
    function abort_unless(bool $condition, int $code, string $message = ''): void
    {
        if (!$condition) abort($code, $message);
    }
}
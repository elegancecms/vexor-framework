<?php

declare(strict_types=1);

namespace Vexor\Core\Http;

/**
 * Vexor HTTP Request
 * 
 * Immutable request object representing the current HTTP request.
 * Includes built-in XSS sanitization and input validation.
 */
class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $server;
    private array $cookies;
    private array $headers;
    private string $rawBody;
    private ?array $jsonBody = null;
    private array $attributes = [];

    public function __construct(
        array $query = [],
        array $body = [],
        array $files = [],
        array $server = [],
        array $cookies = []
    ) {
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $files;
        $this->server  = $server;
        $this->cookies = $cookies;
        $this->headers = $this->parseHeaders($server);
        $this->rawBody = file_get_contents('php://input') ?: '';
    }

    public static function createFromGlobals(): static
    {
        return new static(
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $_COOKIE
        );
    }

    // ── Input ────────────────────────────────────────────────────────────────

    public function input(string $key, mixed $default = null): mixed
    {
        $all = array_merge($this->query, $this->body);
        return $all[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * Get sanitized (XSS-safe) input value
     */
    public function safe(string $key, mixed $default = null): mixed
    {
        $value = $this->input($key, $default);
        return is_string($value) ? $this->sanitize($value) : $value;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function json(string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonBody === null) {
            $this->jsonBody = json_decode($this->rawBody, true) ?? [];
        }

        if ($key === null) return $this->jsonBody;
        return $this->jsonBody[$key] ?? $default;
    }

    public function has(string ...$keys): bool
    {
        $all = $this->all();
        foreach ($keys as $key) {
            if (!array_key_exists($key, $all)) return false;
        }
        return true;
    }

    public function only(string ...$keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    public function except(string ...$keys): array
    {
        $all = $this->all();
        return array_diff_key($all, array_flip($keys));
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    // ── Request Meta ─────────────────────────────────────────────────────────

    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        // Support method override via POST field or header
        if ($method === 'POST') {
            $override = $this->body['_method'] ?? $this->header('X-HTTP-Method-Override');
            if ($override) {
                $method = strtoupper($override);
            }
        }

        return $method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function uri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos !== false ? substr($uri, 0, $pos) : $uri;
    }

    public function fullUri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function url(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri();
    }

    public function fullUrl(): string
    {
        return $this->scheme() . '://' . $this->host() . $this->fullUri();
    }

    public function scheme(): string
    {
        if (($this->server['HTTPS'] ?? '') === 'on') return 'https';
        if (($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return 'https';
        return 'http';
    }

    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->server[$key])) {
                return explode(',', $this->server[$key])[0];
            }
        }
        return '0.0.0.0';
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type') ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept') ?? '', 'application/json');
    }

    public function isSecure(): bool
    {
        return $this->scheme() === 'https';
    }

    // ── Headers ───────────────────────────────────────────────────────────────

    public function header(string $key, mixed $default = null): mixed
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function apiKey(): ?string
    {
        return $this->header('x-api-key') ?? $this->query('api_key');
    }

    private function parseHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    // ── Attributes (router params, middleware data) ────────────────────────

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    // ── Cookies ───────────────────────────────────────────────────────────────

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    // ── Security ─────────────────────────────────────────────────────────────

    private function sanitize(string $value): string
    {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }
}

<?php

declare(strict_types=1);

namespace Vexor\Core\Http;

/**
 * Vexor HTTP Response
 * 
 * Fluent response builder with security headers baked in.
 */
class Response
{
    private int $statusCode = 200;
    private string $body = '';
    private array $headers = [];

    private const STATUS_TEXTS = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 422 => 'Unprocessable Entity',
        429 => 'Too Many Requests', 500 => 'Internal Server Error',
    ];

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $status;
        $this->headers = array_merge($this->defaultSecurityHeaders(), $headers);
    }

    private function defaultSecurityHeaders(): array
    {
        return [
            'X-Content-Type-Options'    => 'nosniff',
            'X-Frame-Options'           => 'SAMEORIGIN',
            'X-XSS-Protection'          => '1; mode=block',
            'Referrer-Policy'           => 'strict-origin-when-cross-origin',
            'Content-Security-Policy'   => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=()',
            'X-Powered-By'              => 'Vexor/1.0',
        ];
    }

    // ── Factory Methods ───────────────────────────────────────────────────────

    public static function make(string $body = '', int $status = 200, array $headers = []): static
    {
        return new static($body, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): static
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $instance = new static($body, $status, $headers);
        $instance->header('Content-Type', 'application/json; charset=UTF-8');
        return $instance;
    }

    public static function redirect(string $url, int $status = 302): static
    {
        $instance = new static('', $status);
        $instance->header('Location', $url);
        return $instance;
    }

    public static function noContent(): static
    {
        return new static('', 204);
    }

    // ── Fluent Builder ────────────────────────────────────────────────────────

    public function withStatus(int $code): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        return $clone;
    }

    public function header(string $key, string $value): static
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->headers[$key] = $value;
        }
        return $this;
    }

    public function withBody(string $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    // ── Cookie ────────────────────────────────────────────────────────────────

    public function cookie(
        string $name,
        string $value,
        int $minutes = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $expire = $minutes > 0 ? time() + ($minutes * 60) : 0;
        $cookie = urlencode($name) . '=' . urlencode($value);
        $cookie .= $expire ? "; Expires=" . gmdate('D, d-M-Y H:i:s T', $expire) : '';
        $cookie .= "; Path={$path}";
        $cookie .= $domain ? "; Domain={$domain}" : '';
        $cookie .= $secure ? "; Secure" : '';
        $cookie .= $httpOnly ? "; HttpOnly" : '';
        $cookie .= "; SameSite={$sameSite}";

        $this->headers['Set-Cookie'] = $cookie;
        return $this;
    }

    public function forgetCookie(string $name): static
    {
        return $this->cookie($name, '', -1);
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    public function send(): void
    {
        if (!headers_sent()) {
            $statusText = self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';
            header("HTTP/1.1 {$this->statusCode} {$statusText}", true, $this->statusCode);

            foreach ($this->headers as $key => $value) {
                header("{$key}: {$value}", true);
            }
        }

        echo $this->body;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getStatusCode(): int { return $this->statusCode; }
    public function getBody(): string { return $this->body; }
    public function getHeaders(): array { return $this->headers; }

    public function isSuccessful(): bool { return $this->statusCode >= 200 && $this->statusCode < 300; }
    public function isRedirect(): bool { return in_array($this->statusCode, [301, 302, 303, 307, 308]); }
    public function isClientError(): bool { return $this->statusCode >= 400 && $this->statusCode < 500; }
    public function isServerError(): bool { return $this->statusCode >= 500; }
}

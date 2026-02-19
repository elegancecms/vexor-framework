<?php

declare(strict_types=1);

namespace Vexor\Security;

use Vexor\Application;
use Vexor\Http\Request;
use Vexor\Exceptions\HttpException;
use Vexor\Exceptions\SecurityException;

/**
 * Vexor SecurityManager
 * 
 * Centralizes all security operations:
 * - CSRF token generation and validation
 * - XSS sanitization (output encoding)
 * - SQL injection detection
 * - Rate limiting (token bucket)
 * - Input validation rules engine
 */
class SecurityManager
{
    private Application $app;
    private const CSRF_SESSION_KEY = '_vexor_csrf_token';
    private const CSRF_BYPASS_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(Application $app)
    {
        $this->app = $app;

        if (session_status() === PHP_SESSION_NONE) {
            $this->startSecureSession();
        }
    }

    private function startSecureSession(): void
    {
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $cookieParams['lifetime'],
            'path'     => '/',
            'domain'   => $cookieParams['domain'],
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_name('VEXOR_SESSION');
        session_start();
        session_regenerate_id(false);
    }

    // ── Request Validation ────────────────────────────────────────────────────

    public function validateRequest(Request $request): void
    {
        $this->checkRateLimit($request);

        if (!in_array($request->method(), self::CSRF_BYPASS_METHODS)) {
            // CSRF only for web routes (skip if Bearer token or API key present)
            if (!$request->bearerToken() && !$request->apiKey()) {
                $this->validateCsrf($request);
            }
        }

        $this->detectSqlInjection($request);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION[self::CSRF_SESSION_KEY])) {
            $_SESSION[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_SESSION_KEY];
    }

    public function validateCsrf(Request $request): void
    {
        $sessionToken = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        $inputToken   = $request->input('_token') ?? $request->header('X-CSRF-Token');

        if (!$sessionToken || !$inputToken || !hash_equals($sessionToken, $inputToken)) {
            throw new SecurityException('CSRF token mismatch.', 419);
        }
    }

    public function csrfField(): string
    {
        $token = $this->generateCsrfToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }

    public function csrfMeta(): string
    {
        $token = $this->generateCsrfToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES) . '">';
    }

    // ── XSS ──────────────────────────────────────────────────────────────────

    public function escape(string $value, string $context = 'html'): string
    {
        return match ($context) {
            'html'       => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'attr'       => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'js'         => json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
            'url'        => urlencode($value),
            'css'        => preg_replace('/[^a-zA-Z0-9\-_]/', '', $value),
            default      => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        };
    }

    public function sanitizeHtml(string $html, array $allowedTags = []): string
    {
        if (empty($allowedTags)) {
            return strip_tags($html);
        }
        $allowed = implode('', array_map(fn($t) => "<{$t}>", $allowedTags));
        return strip_tags($html, $allowed);
    }

    public function purify(string $input): string
    {
        // Strip null bytes
        $input = str_replace("\0", '', $input);
        // Normalize line endings
        $input = str_replace(["\r\n", "\r"], "\n", $input);
        // Remove control characters except tab and newline
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        // HTML encode
        return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── SQL Injection Detection ───────────────────────────────────────────────

    private function detectSqlInjection(Request $request): void
    {
        $suspicious = [
            '/(\bUNION\b.*\bSELECT\b)/i',
            '/(\bDROP\b.*\bTABLE\b)/i',
            '/(\bINSERT\b.*\bINTO\b)/i',
            '/(\bDELETE\b.*\bFROM\b)/i',
            '/(\bOR\b\s+[\'"0-9]+\s*=\s*[\'"0-9]+)/i',
            '/(--|\#|\/\*|\*\/|;--)/i',
            '/(\bEXEC\b|\bEXECUTE\b)/i',
            '/(\bxp_\w+)/i',
            '/(\bDECLARE\b.*\b@)/i',
        ];

        $inputs = array_merge($request->all(), ['uri' => $request->uri()]);

        foreach ($inputs as $key => $value) {
            if (!is_string($value)) continue;
            foreach ($suspicious as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->logSecurityEvent('sql_injection_attempt', [
                        'field' => $key,
                        'ip'    => $request->ip(),
                        'uri'   => $request->uri(),
                    ]);
                    throw new SecurityException('Malicious input detected.', 400);
                }
            }
        }
    }

    // ── Rate Limiting (Token Bucket) ──────────────────────────────────────────

    public function checkRateLimit(Request $request, string $key = null, int $maxRequests = 60, int $window = 60): void
    {
        $key ??= 'rl:' . $request->ip();
        $storageFile = sys_get_temp_dir() . '/vexor_rl_' . md5($key) . '.json';

        $data = ['count' => 0, 'reset_at' => time() + $window];

        if (file_exists($storageFile)) {
            $stored = json_decode(file_get_contents($storageFile), true);
            if ($stored && $stored['reset_at'] > time()) {
                $data = $stored;
            }
        }

        $data['count']++;
        file_put_contents($storageFile, json_encode($data), LOCK_EX);

        if ($data['count'] > $maxRequests) {
            throw new HttpException(429, 'Too many requests. Please slow down.');
        }
    }

    public function rateLimit(string $key, int $maxRequests = 60, int $window = 60): bool
    {
        $storageFile = sys_get_temp_dir() . '/vexor_rl_' . md5($key) . '.json';
        $data = ['count' => 0, 'reset_at' => time() + $window];

        if (file_exists($storageFile)) {
            $stored = json_decode(file_get_contents($storageFile), true);
            if ($stored && $stored['reset_at'] > time()) {
                $data = $stored;
            }
        }

        $data['count']++;
        file_put_contents($storageFile, json_encode($data), LOCK_EX);

        return $data['count'] <= $maxRequests;
    }

    // ── Password Hashing ──────────────────────────────────────────────────────

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 2,
        ]);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    // ── Encryption ────────────────────────────────────────────────────────────

    public function encrypt(string $data, string $key = null): string
    {
        $key    = $key ?? $this->app->config('app.key', 'default_key_change_me');
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($data, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload, string $key = null): string|false
    {
        $key     = $key ?? $this->app->config('app.key', 'default_key_change_me');
        $decoded = base64_decode($payload);

        if ($decoded === false || strlen($decoded) < 32) return false;

        $iv     = substr($decoded, 0, 16);
        $tag    = substr($decoded, 16, 16);
        $cipher = substr($decoded, 32);

        return openssl_decrypt($cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }

    // ── 2FA ───────────────────────────────────────────────────────────────────

    public function generate2FASecret(): string
    {
        return base32_encode(random_bytes(20));
    }

    public function generateTotp(string $secret, int $timeStep = 30, int $digits = 6): string
    {
        $time    = intdiv(time(), $timeStep);
        $keyBytes = $this->base32Decode($secret);
        $msg     = pack('N*', 0) . pack('N*', $time);
        $hash    = hash_hmac('sha1', $msg, $keyBytes, true);
        $offset  = ord($hash[-1]) & 0x0F;
        $otp     = ((ord($hash[$offset]) & 0x7F) << 24 |
                    (ord($hash[$offset + 1]) & 0xFF) << 16 |
                    (ord($hash[$offset + 2]) & 0xFF) << 8 |
                    (ord($hash[$offset + 3]) & 0xFF)) % (10 ** $digits);

        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    public function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        $time = intdiv(time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->generateTotp($secret), $code)) return true;
        }
        return false;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32   = strtoupper(rtrim($base32, '='));
        $binary   = '';

        foreach (str_split($base32) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $binary .= sprintf('%05b', $pos);
        }

        $bytes = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }

    // ── API Key Management ────────────────────────────────────────────────────

    public function generateApiKey(string $prefix = 'vxr'): string
    {
        $random = bin2hex(random_bytes(32));
        $key    = $prefix . '_' . $random;
        return $key;
    }

    public function hashApiKey(string $key): string
    {
        return hash('sha256', $key);
    }

    // ── Logging ───────────────────────────────────────────────────────────────

    private function logSecurityEvent(string $event, array $context = []): void
    {
        $logPath = $this->app->basePath('storage/logs');
        $logFile = $logPath . '/security.log';

        if (!is_dir($logPath)) {
            mkdir($logPath, 0750, true);
        }

        $entry = json_encode(array_merge([
            'event'     => $event,
            'timestamp' => date('Y-m-d H:i:s'),
        ], $context)) . PHP_EOL;

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

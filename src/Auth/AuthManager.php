<?php

declare(strict_types=1);

namespace Vexor\Auth;

use Vexor\Application;
use Vexor\Http\Request;
use Vexor\Security\SecurityManager;
use Vexor\Exceptions\AuthException;

/**
 * Vexor AuthManager
 * 
 * Dual-mode authentication:
 * - Session-based (web)
 * - JWT-based (API)
 * 
 * Features:
 * - Automatic provider detection
 * - Token refresh
 * - Remember me
 * - 2FA integration
 * - API key validation
 */
class AuthManager
{
    private Application $app;
    private SecurityManager $security;
    private mixed $user = null;
    private bool $resolved = false;
    private const SESSION_KEY = '_vexor_auth_user';

    public function __construct(Application $app, SecurityManager $security)
    {
        $this->app      = $app;
        $this->security = $security;
    }

    // ── Attempt Login ─────────────────────────────────────────────────────────

    public function attempt(array $credentials, bool $remember = false): bool
    {
        $userModel = $this->app->config('auth.model', \App\Models\User::class);

        if (!class_exists($userModel)) {
            throw new AuthException("Auth model [{$userModel}] not found.");
        }

        $user = $userModel::findByCredential(
            $credentials['email'] ?? $credentials['username'] ?? '',
            'email'
        );

        if (!$user) return false;

        if (!$this->security->verifyPassword($credentials['password'], $user->password)) {
            return false;
        }

        // Check if password needs rehashing
        if ($this->security->needsRehash($user->password)) {
            $user->password = $this->security->hashPassword($credentials['password']);
            $user->save();
        }

        $this->login($user, $remember);
        return true;
    }

    public function login(mixed $user, bool $remember = false): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id'       => $user->id,
            'model'    => get_class($user),
            'remember' => $remember,
        ];

        if ($remember) {
            $token = bin2hex(random_bytes(40));
            $user->remember_token = hash('sha256', $token);
            $user->save();
        }

        session_regenerate_id(true);
        $this->user = $user;
    }

    public function logout(): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        $this->user    = null;
        $this->resolved = false;

        session_regenerate_id(true);
    }

    // ── Check Auth ────────────────────────────────────────────────────────────

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function id(): mixed
    {
        return $this->user()?->id;
    }

    public function user(): mixed
    {
        if ($this->resolved) return $this->user;

        $this->resolved = true;

        // Try session first
        if (!empty($_SESSION[self::SESSION_KEY])) {
            $data      = $_SESSION[self::SESSION_KEY];
            $userModel = $data['model'];
            $this->user = $userModel::find($data['id']);
            return $this->user;
        }

        return null;
    }

    public function userFromRequest(Request $request): mixed
    {
        // Try JWT token
        $token = $request->bearerToken();
        if ($token) {
            return $this->authenticateWithJwt($token);
        }

        // Try API key
        $apiKey = $request->apiKey();
        if ($apiKey) {
            return $this->authenticateWithApiKey($apiKey);
        }

        return $this->user();
    }

    // ── JWT ───────────────────────────────────────────────────────────────────

    public function generateJwt(mixed $user, int $ttl = 3600): string
    {
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'sub'   => $user->id,
            'model' => get_class($user),
            'iat'   => time(),
            'exp'   => time() + $ttl,
            'jti'   => bin2hex(random_bytes(16)),
        ]));

        $secret    = $this->app->config('app.jwt_secret', $this->app->config('app.key'));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));

        return "$header.$payload.$signature";
    }

    public function generateRefreshToken(mixed $user): string
    {
        return $this->generateJwt($user, 30 * 24 * 3600); // 30 days
    }

    public function validateJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        $secret   = $this->app->config('app.jwt_secret', $this->app->config('app.key'));
        $expected = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));

        if (!hash_equals($expected, $signature)) return null;

        $data = json_decode($this->base64UrlDecode($payload), true);

        if (!$data || $data['exp'] < time()) return null;

        return $data;
    }

    private function authenticateWithJwt(string $token): mixed
    {
        $data = $this->validateJwt($token);
        if (!$data) return null;

        $userModel  = $data['model'];
        $this->user = $userModel::find($data['sub']);
        return $this->user;
    }

    private function authenticateWithApiKey(string $key): mixed
    {
        $hashedKey = $this->security->hashApiKey($key);
        $userModel = $this->app->config('auth.model', \App\Models\User::class);

        if (!class_exists($userModel)) return null;

        $this->user = $userModel::findByApiKey($hashedKey);
        return $this->user;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    // ── Password Reset ────────────────────────────────────────────────────────

    public function generatePasswordResetToken(mixed $user): string
    {
        $token    = bin2hex(random_bytes(32));
        $expiry   = time() + 3600;
        $hashedToken = hash('sha256', $token);

        $user->password_reset_token  = $hashedToken;
        $user->password_reset_expiry = $expiry;
        $user->save();

        return $token;
    }

    public function resetPassword(string $token, string $email, string $newPassword): bool
    {
        $userModel = $this->app->config('auth.model', \App\Models\User::class);
        $user = $userModel::findByEmail($email);

        if (!$user) return false;

        $hashedToken = hash('sha256', $token);

        if (!hash_equals($user->password_reset_token ?? '', $hashedToken)) return false;

        if ($user->password_reset_expiry < time()) return false;

        $user->password              = $this->security->hashPassword($newPassword);
        $user->password_reset_token  = null;
        $user->password_reset_expiry = null;
        $user->save();

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Vexor\Core\Http;

use Vexor\Core\Application;

/**
 * Base Controller
 * 
 * Provides helpers for:
 * - JSON and HTML responses
 * - View rendering
 * - Validation
 * - Authentication access
 * - Redirect helpers
 */
abstract class Controller
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    // ── Response Helpers ──────────────────────────────────────────────────────

    protected function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers);
    }

    protected function success(mixed $data = null, string $message = 'Success', int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 400, mixed $errors = null): Response
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors !== null) $body['errors'] = $errors;
        return Response::json($body, $status);
    }

    protected function view(string $template, array $data = []): Response
    {
        $content = $this->renderView($template, $data);
        return Response::make($content, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    protected function back(): Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        return Response::redirect($referer);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    // ── View Rendering ────────────────────────────────────────────────────────

    private function renderView(string $template, array $data = []): string
    {
        $viewPath = $this->app->basePath("resources/views/{$template}.php");

        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View [{$template}] not found at [{$viewPath}].");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        return ob_get_clean();
    }

    // ── Validation ────────────────────────────────────────────────────────────

    protected function validate(Request $request, array $rules): array
    {
        $data   = $request->all();
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value      = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $error = $this->applyRule($field, $value, $rule);
                if ($error) {
                    $errors[$field][] = $error;
                }
            }
        }

        if (!empty($errors)) {
            throw new \Vexor\Core\Exceptions\ValidationException($errors);
        }

        return array_intersect_key($data, $rules);
    }

    private function applyRule(string $field, mixed $value, string $rule): ?string
    {
        if (str_contains($rule, ':')) {
            [$ruleName, $param] = explode(':', $rule, 2);
        } else {
            [$ruleName, $param] = [$rule, null];
        }

        return match ($ruleName) {
            'required'  => (empty($value) && $value !== '0') ? "{$field} is required." : null,
            'email'     => (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) ? "{$field} must be a valid email." : null,
            'min'       => (!empty($value) && strlen((string) $value) < (int) $param) ? "{$field} must be at least {$param} characters." : null,
            'max'       => (!empty($value) && strlen((string) $value) > (int) $param) ? "{$field} may not exceed {$param} characters." : null,
            'numeric'   => (!empty($value) && !is_numeric($value)) ? "{$field} must be numeric." : null,
            'integer'   => (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) ? "{$field} must be an integer." : null,
            'url'       => (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) ? "{$field} must be a valid URL." : null,
            'confirmed' => (!empty($value) && $value !== ($_POST[$field . '_confirmation'] ?? null)) ? "{$field} confirmation does not match." : null,
            'in'        => (!empty($value) && !in_array($value, explode(',', $param ?? ''))) ? "{$field} must be one of: {$param}." : null,
            'regex'     => (!empty($value) && !preg_match($param, (string) $value)) ? "{$field} format is invalid." : null,
            default     => null,
        };
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    protected function auth(): \Vexor\Core\Auth\AuthManager
    {
        return $this->app->make(\Vexor\Core\Auth\AuthManager::class);
    }

    protected function user(): mixed
    {
        return $this->auth()->user();
    }

    // ── Security ─────────────────────────────────────────────────────────────

    protected function security(): \Vexor\Core\Security\SecurityManager
    {
        return $this->app->make(\Vexor\Core\Security\SecurityManager::class);
    }

    protected function csrfField(): string
    {
        return $this->security()->csrfField();
    }
}

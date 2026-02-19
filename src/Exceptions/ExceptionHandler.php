<?php

declare(strict_types=1);

namespace Vexor\Exceptions;

use Vexor\Application;
use Vexor\Http\Response;

/**
 * Vexor Exception Handler
 * 
 * Converts all exceptions to appropriate HTTP responses.
 * Provides detailed errors in debug mode, safe messages in production.
 */
class ExceptionHandler
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->registerPhpHandlers();
    }

    private function registerPhpHandlers(): void
    {
        set_exception_handler([$this, 'handleUncaught']);
        set_error_handler(function (int $severity, string $message, string $file, int $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public function handleUncaught(\Throwable $e): void
    {
        $this->handle($e)->send();
    }

    public function handle(\Throwable $e): Response
    {
        $debug = (bool) $this->app->config('app.debug', false);

        // Log the error
        $this->log($e);

        return match (true) {
            $e instanceof ValidationException => $this->handleValidation($e, $debug),
            $e instanceof HttpException       => $this->handleHttp($e, $debug),
            $e instanceof SecurityException   => $this->handleSecurity($e, $debug),
            $e instanceof AuthException       => $this->handleAuth($e, $debug),
            default                           => $this->handleGeneric($e, $debug),
        };
    }

    private function handleValidation(ValidationException $e, bool $debug): Response
    {
        return Response::json([
            'success' => false,
            'message' => $e->getMessage(),
            'errors'  => $e->getErrors(),
        ], 422);
    }

    private function handleHttp(HttpException $e, bool $debug): Response
    {
        $status = $e->getStatusCode();
        $body   = [
            'success' => false,
            'status'  => $status,
            'message' => $e->getMessage() ?: $this->getDefaultMessage($status),
        ];

        if ($debug) {
            $body['debug'] = $this->debugInfo($e);
        }

        return Response::json($body, $status);
    }

    private function handleSecurity(SecurityException $e, bool $debug): Response
    {
        $body = [
            'success' => false,
            'message' => $debug ? $e->getMessage() : 'Security violation detected.',
        ];
        return Response::json($body, $e->getCode() ?: 400);
    }

    private function handleAuth(AuthException $e, bool $debug): Response
    {
        return Response::json([
            'success' => false,
            'message' => $e->getMessage() ?: 'Authentication failed.',
        ], 401);
    }

    private function handleGeneric(\Throwable $e, bool $debug): Response
    {
        $body = [
            'success' => false,
            'message' => $debug ? $e->getMessage() : 'An internal server error occurred.',
        ];

        if ($debug) {
            $body['debug'] = $this->debugInfo($e);
        }

        return Response::json($body, 500);
    }

    private function debugInfo(\Throwable $e): array
    {
        return [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => array_slice($e->getTrace(), 0, 10),
        ];
    }

    private function getDefaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Error',
        };
    }

    private function log(\Throwable $e): void
    {
        $logPath = $this->app->basePath('storage/logs');

        if (!is_dir($logPath)) {
            mkdir($logPath, 0750, true);
        }

        $entry = sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        file_put_contents($logPath . '/error.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
